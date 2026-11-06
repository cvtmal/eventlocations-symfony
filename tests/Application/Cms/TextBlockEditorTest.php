<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Geo\ValueObject\Canton;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Cms\ResetsContentBlocks;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Tests\Support\Search\LandingFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Zenstruck\Foundry\Test\Factories;

/**
 * The text block screens of #26: the list of declared blocks, the editor with the block's own limit, a save
 * that reaches the public page with its Markdown made safe, a clear that removes the text, bad tokens, the
 * stale version (409), the no-op save (P9) and the 404 for anything not declared.
 */
final class TextBlockEditorTest extends WebTestCase
{
    use AdminSession {
        setUp as private startAdminSession;
        tearDown as private endAdminSession;
    }
    use Factories;
    use LandingFixtures;
    use ResetsContentBlocks;

    private const string EDITOR = '/admin/textbausteine/listing.intro';

    protected function setUp(): void
    {
        $this->startAdminSession();
        $this->resetContentBlocks();
    }

    protected function tearDown(): void
    {
        $this->resetContentBlocks();
        $this->endAdminSession();
    }

    public function test_anonymous_is_sent_to_the_login(): void
    {
        $this->client->request('GET', '/admin/textbausteine');
        self::assertResponseRedirects('/admin/login');

        $this->client->request('GET', self::EDITOR);
        self::assertResponseRedirects('/admin/login');
    }

    public function test_the_list_shows_every_declared_block_with_its_state(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->store('footer.note', 'Alle Angaben ohne Gewähr.');

        $crawler = $this->client->request('GET', '/admin/textbausteine');

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                ['Hinweis in der Fusszeile', 'Fusszeile jeder öffentlichen Seite, unter den Links', 'eigener Text'],
                ['Text der Startseite', 'Auf der Startseite, über den Regionen', 'leer'],
                ['Einleitung der Suchseite', 'Unter dem Titel der Suchseite ohne Filter, nur auf Seite 1', 'leer'],
            ],
            $crawler->filter('#block-list tbody tr')->each(static fn (Crawler $row): array => \array_slice($row->filter('td')->each(static fn (Crawler $cell): string => $cell->text()), 0, 3)),
        );
        self::assertSame('/admin/textbausteine/footer.note', $crawler->filter('#block-list a')->first()->attr('href'));
        self::assertSame('Textbausteine', $crawler->filter('.sidebar__item--active')->text());
    }

    public function test_a_save_reaches_the_public_page_with_its_markdown_made_safe(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inCanton(Canton::BE, 1);
        $purges = $this->purger()->listings;

        $crawler = $this->client->request('GET', self::EDITOR);
        self::assertResponseIsSuccessful();
        self::assertSame('0 / 600', $crawler->filter('#text_block_locales_de_body_counter')->text());
        self::assertSame('Textbausteine', $crawler->filter('.sidebar__item--active')->text());

        $this->client->submit($crawler->filter('form#text-block-form')->form(), [
            'text_block[locales][de][body]' => "Finde die **passende** Location.\r\n\r\n<script>alert(1)</script>\r\n\r\n[Klick](javascript:alert(1))",
        ]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects(self::EDITOR);
        self::assertSame('«Einleitung der Suchseite» gespeichert.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame($purges + 1, $this->purger()->listings);
        self::assertSame(
            ['{"fields": ["body"], "locales": ["de"]}'],
            $this->connection->fetchFirstColumn("SELECT changes::text FROM admin_audit_log WHERE subject_type = 'content_block' AND subject_id = 'listing.intro'"),
        );

        $intro = $this->client->request('GET', '/hochzeitslocations')->filter('.listing-hero__intro');
        self::assertSame('passende', $intro->filter('strong')->text());
        self::assertStringNotContainsString('<script', $intro->html());
        self::assertStringContainsString('&lt;script&gt;', $intro->html());
        self::assertStringNotContainsString('javascript:', $intro->html());
        self::assertCount(0, $intro->filter('a[href]'));
    }

    public function test_clearing_the_body_removes_the_text(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inCanton(Canton::BE, 1);
        $this->client->submit($this->form(), ['text_block[locales][de][body]' => 'Willkommen.']);
        self::assertCount(1, $this->client->request('GET', '/hochzeitslocations')->filter('.listing-hero__intro'));

        $this->client->submit($this->form(), ['text_block[locales][de][body]' => "  \r\n "]);

        self::assertResponseStatusCodeSame(303);
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM content_blocks WHERE key = 'listing.intro'"));
        self::assertCount(0, $this->client->request('GET', '/hochzeitslocations')->filter('.listing-hero__intro'));
    }

    public function test_a_body_over_the_block_limit_is_refused(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', '/admin/textbausteine/footer.note');
        self::assertSame('0 / 300', $crawler->filter('#text_block_locales_de_body_counter')->text());
        $crawler = $this->client->submit($crawler->filter('form#text-block-form')->form(), ['text_block[locales][de][body]' => str_repeat('a', 301)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Bitte höchstens 300 Zeichen eingeben.', $crawler->filter('#text_block_locales_de_body_error')->text());
        self::assertSame('true', $crawler->filter('#text_block_locales_de_body')->attr('aria-invalid'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM content_blocks'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badVersions(): iterable
    {
        yield 'not a token' => ['abc'];
        yield 'empty' => [''];
        yield 'invalid UTF-8, what %FF decodes to' => ["\xFF"];
        yield 'upper-case hex' => [strtoupper(hash('sha256', ''))];
    }

    #[DataProvider('badVersions')]
    public function test_a_bad_version_is_a_422_and_changes_nothing(string $version): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->submit($this->form(), ['text_block[locales][de][body]' => 'Willkommen.', 'text_block[version]' => $version]);

        self::assertResponseStatusCodeSame(422);
        $this->assertNothingStored();
    }

    public function test_a_missing_csrf_token_and_an_empty_post_are_a_422_and_change_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);

        $form = $this->form();
        $form->setValues(['text_block[locales][de][body]' => 'Willkommen.']);
        $form->remove('text_block[_token]');
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', self::EDITOR);
        self::assertResponseStatusCodeSame(422);

        $this->assertNothingStored();
    }

    public function test_a_stale_version_is_a_conflict_that_keeps_the_input(): void
    {
        $this->logInAs(AdminRole::Editor);
        $form = $this->form();
        $this->store('listing.intro', 'Von jemand anderem.');

        $crawler = $this->client->submit($form, ['text_block[locales][de][body]' => 'Mein Text.']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('Mein Text.', $crawler->filter('#text_block_locales_de_body')->text());
        self::assertSame(self::EDITOR, $crawler->filter('.content-editor__stale a')->attr('href'));
        self::assertSame('Von jemand anderem.', $this->connection->fetchOne("SELECT body_markdown FROM content_blocks WHERE key = 'listing.intro'"));
    }

    public function test_an_unchanged_save_dispatches_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->store('listing.intro', 'Willkommen.');
        $purges = $this->purger()->listings;

        $this->client->submit($this->form());

        self::assertResponseStatusCodeSame(303);
        self::assertSame('Keine Änderungen an «Einleitung der Suchseite».', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'content_block'"));
        self::assertSame($purges, $this->purger()->listings);
    }

    public function test_the_home_text_hint_quotes_the_paragraphs_the_empty_home_page_shows(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', '/admin/textbausteine/home.text');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('erscheint an dieser Stelle nichts', $crawler->filter('#text-block-form')->text());
        $hint = $crawler->filter('#text-block-default p')->each(static fn (Crawler $paragraph): string => $paragraph->text());
        self::assertSame('Ohne eigenen Text erscheinen diese 2 Absätze:', array_shift($hint));
        self::assertStringStartsWith('«Jede Hochzeit ist einzigartig, genau wie ihr Ort.', $hint[0]);
        self::assertStringStartsWith('«Mit unseren Filtern grenzt du die Auswahl', $hint[1]);

        $home = $this->client->request('GET', '/')->filter('.home-about__text > p')->each(static fn (Crawler $paragraph): string => '«'.$paragraph->text().'»');
        self::assertSame($home, $hint);
    }

    public function test_a_block_with_no_default_says_nothing_appears(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', self::EDITOR);

        self::assertStringContainsString('Ohne eigenen Text erscheint an dieser Stelle nichts.', $crawler->filter('#text-block-form')->text());
        self::assertCount(0, $crawler->filter('#text-block-default'));
    }

    public function test_only_a_declared_block_is_editable(): void
    {
        $this->logInAs(AdminRole::Editor);

        foreach (['landing.canton.zh.title', 'listing.outro', 'footer.note.x'] as $key) {
            $this->client->request('GET', '/admin/textbausteine/'.$key);
            self::assertResponseStatusCodeSame(404, $key);
        }
        $this->client->request('POST', '/admin/textbausteine/listing.outro');
        self::assertResponseStatusCodeSame(404);
    }

    private function form(): Form
    {
        return $this->client->request('GET', self::EDITOR)->filter('form#text-block-form')->form();
    }

    private function assertNothingStored(): void
    {
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM content_blocks'));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'content_block'"));
    }

    private function store(string $key, string $body): void
    {
        $this->connection->insert('content_blocks', ['key' => $key, 'locale' => 'de', 'body_markdown' => $body, 'updated_at' => '2026-10-30 09:00:00+00']);
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);

        return $purger;
    }
}
