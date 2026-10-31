<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Factory\VenueTermFactory;
use App\Geo\ValueObject\Canton;
use App\Shared\ReadModel\ContentBlocks;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Cms\HoldsAdvisoryLocks;
use App\Tests\Support\Cms\ResetsContentBlocks;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Tests\Support\Search\LandingFixtures;
use App\Venues\ValueObject\TermGroup;
use Doctrine\DBAL\ArrayParameterType;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Zenstruck\Foundry\Test\Factories;

/**
 * The landing page screens of #26 (CMS-12): the list with its threshold and text pills, the editor with #17's
 * texts as placeholders and in the search-result preview, a save that reaches the public page of each type, a
 * clear that brings #17's text back, the limits, bad tokens, the stale version (409), the no-op save (P9), a
 * failed snapshot refresh and the 404s.
 */
final class LandingPageEditorTest extends WebTestCase
{
    use AdminSession {
        setUp as private startAdminSession;
        tearDown as private endAdminSession;
    }
    use Factories;
    use HoldsAdvisoryLocks;
    use ImportsTownDataset;
    use LandingFixtures;
    use ResetsContentBlocks;

    private const string EDITOR = '/admin/landingpages/canton.zh';
    private const array KEYS = ['landing.canton.zh.intro', 'landing.canton.zh.title', 'landing.canton.zh.description'];

    protected function setUp(): void
    {
        $this->startAdminSession();
        $this->resetContentBlocks();
    }

    protected function tearDown(): void
    {
        $this->releaseAdvisoryLocks();
        $this->resetContentBlocks();
        $this->endAdminSession();
    }

    public function test_anonymous_is_sent_to_the_login(): void
    {
        $this->client->request('GET', '/admin/landingpages');
        self::assertResponseRedirects('/admin/login');

        $this->client->request('GET', self::EDITOR);
        self::assertResponseRedirects('/admin/login');
    }

    public function test_the_list_shows_threshold_and_text_state_per_page(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inCanton(Canton::ZH, 5);
        $this->inCanton(Canton::BE, 1);
        $this->store('landing.canton.zh.title', 'Heiraten im Kanton Zürich');

        $crawler = $this->client->request('GET', '/admin/landingpages');

        self::assertResponseIsSuccessful();
        self::assertSame(['online', 'eigener Text'], $this->pills($crawler, 'canton.zh'));
        self::assertSame(['unter Schwelle', 'Standard'], $this->pills($crawler, 'canton.be'));
        self::assertSame('Kanton', $this->row($crawler, 'canton.zh')->filter('td')->eq(1)->text());
        self::assertSame('5', $this->row($crawler, 'canton.zh')->filter('td')->eq(2)->text());
        self::assertSame('Landingpages', $crawler->filter('.sidebar__item--active')->text());
    }

    public function test_the_type_tab_and_the_search_filter_the_list(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inTown('Stäfa', 1);
        $this->inCanton(Canton::BE, 1);

        $crawler = $this->client->request('GET', '/admin/landingpages?typ=town');
        self::assertSame(['town'], $this->types($crawler));
        self::assertStringContainsString('Orte', $crawler->filter('.tab[aria-current="page"]')->text());

        $crawler = $this->client->request('GET', '/admin/landingpages?q=bern');
        self::assertSame(['canton.be'], $this->prefixes($crawler));

        $crawler = $this->client->request('GET', '/admin/landingpages?typ=nonsense&q=xyz-unbekannt');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.empty'));
    }

    public function test_a_filter_without_a_match_says_so_instead_of_the_empty_site_text(): void
    {
        $this->logInAs(AdminRole::Editor);
        $crawler = $this->client->request('GET', '/admin/landingpages');
        self::assertStringContainsString('sobald eine publizierte Location', $crawler->filter('.empty')->text());

        $this->inCanton(Canton::ZH, 1);

        foreach (['q=xyz-unbekannt', 'typ=town', 'typ=building&q=zürich'] as $query) {
            $empty = $this->client->request('GET', '/admin/landingpages?'.$query)->filter('.empty')->text();
            self::assertStringContainsString('Keine Seite passt zu diesem Filter.', $empty, $query);
            self::assertStringNotContainsString('sobald eine publizierte Location', $empty, $query);
        }
    }

    public function test_the_list_pages_by_fifty(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = $this->inCanton(Canton::ZH, 1)[0];
        $codes = $this->connection->fetchFirstColumn("SELECT code FROM taxonomy_terms WHERE term_group = 'building' ORDER BY position");
        foreach ($codes as $code) {
            VenueTermFactory::new()->term(TermGroup::Building, (string) $code)->create(['venue' => $venue]);
        }
        $cantons = [Canton::BE, Canton::LU, Canton::UR, Canton::SZ, Canton::OW, Canton::NW, Canton::GL, Canton::ZG, Canton::FR, Canton::SO, Canton::BS];
        foreach ($cantons as $canton) {
            $this->inCanton($canton, 1);
        }
        $total = 1 + \count($codes) + \count($cantons);
        self::assertGreaterThan(50, $total);

        $first = $this->client->request('GET', '/admin/landingpages');
        $second = $this->client->request('GET', '/admin/landingpages?page=2');

        self::assertCount(50, $this->prefixes($first));
        self::assertCount($total - 50, $this->prefixes($second));
        self::assertSame([], array_intersect($this->prefixes($first), $this->prefixes($second)));
        self::assertCount(1, $second->filter('.pagination a[rel="prev"]'));
        self::assertCount($total - 50, $this->prefixes($this->client->request('GET', '/admin/landingpages?page=99')));
    }

    public function test_the_editor_shows_the_defaults_as_placeholders_and_in_the_preview(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inCanton(Canton::ZH, 5);

        $crawler = $this->client->request('GET', self::EDITOR);

        self::assertResponseIsSuccessful();
        self::assertSame('Hochzeitslocations im Kanton Zürich', $crawler->filter('.record-header h1')->text());
        $title = (string) $crawler->filter('#landing_texts_locales_de_title')->attr('placeholder');
        $description = (string) $crawler->filter('#landing_texts_locales_de_description')->attr('placeholder');
        self::assertStringContainsString('Zürich', $title);
        self::assertNotSame('', $description);
        self::assertStringContainsString('vergleiche Fotos', (string) $crawler->filter('#landing_texts_locales_de_intro')->attr('placeholder'));
        self::assertSame($title, $crawler->filter('[data-serp-target="title"][data-serp-locale="de"]')->text());
        self::assertSame($description, $crawler->filter('[data-serp-target="description"][data-serp-locale="de"]')->text());
        self::assertSame('http://localhost/hochzeitslocations/zuerich', $crawler->filter('.serp-preview__url')->text());
        self::assertSame('title', $crawler->filter('#landing_texts_locales_de_title')->attr('data-serp-source'));
        self::assertSame('0 / 60', $crawler->filter('#landing_texts_locales_de_title_counter')->text());
        self::assertSame('http://localhost/hochzeitslocations/zuerich', $crawler->filter('.record-header__actions a')->attr('href'));
        self::assertSame('Landingpages', $crawler->filter('.sidebar__item--active')->text());
    }

    public function test_a_page_under_the_threshold_is_editable_but_not_linked(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inCanton(Canton::BE, 1);

        $crawler = $this->client->request('GET', '/admin/landingpages/canton.be');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.record-header__actions a'));
        self::assertSame('Die Seite ist noch nicht online.', $crawler->filter('.serp-preview__url')->text());
    }

    public function test_a_save_redirects_purges_audits_and_reaches_the_public_page(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inCanton(Canton::ZH, 5);
        $purges = $this->purger()->listings;

        $this->client->submit($this->form(), [
            'landing_texts[locales][de][intro]' => "Am **Zürichsee** feiern.\r\n\r\nZweiter Absatz.",
            'landing_texts[locales][de][title]' => '  Heiraten im Kanton Zürich ',
            'landing_texts[locales][de][description]' => 'Schlösser, Zunfthäuser und Seeufer: Hochzeitslocations im Kanton Zürich.',
        ]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects(self::EDITOR);
        $crawler = $this->client->followRedirect();
        self::assertSame('Texte von «Hochzeitslocations im Kanton Zürich» gespeichert.', $crawler->filter('.toast')->text());
        self::assertSame('Heiraten im Kanton Zürich', $crawler->filter('#landing_texts_locales_de_title')->attr('value'));
        self::assertSame('Heiraten im Kanton Zürich', $crawler->filter('[data-serp-target="title"]')->text());
        self::assertStringContainsString('zuletzt bearbeitet von Andrea Keller', $crawler->filter('.record-header__subtitle')->text());
        self::assertSame([
            'landing.canton.zh.description' => 'Schlösser, Zunfthäuser und Seeufer: Hochzeitslocations im Kanton Zürich.',
            'landing.canton.zh.intro' => "Am **Zürichsee** feiern.\n\nZweiter Absatz.",
            'landing.canton.zh.title' => 'Heiraten im Kanton Zürich',
        ], $this->bodies());
        self::assertSame($purges + 1, $this->purger()->listings);
        self::assertSame(
            ['{"fields": ["description", "intro", "title"], "locales": ["de"]}'],
            $this->connection->fetchFirstColumn("SELECT changes::text FROM admin_audit_log WHERE subject_type = 'landing_page' AND subject_id = 'canton.zh'"),
        );

        $public = $this->client->request('GET', '/hochzeitslocations/zuerich');
        self::assertSame('Heiraten im Kanton Zürich', $public->filter('title')->text());
        self::assertSame('Zürichsee', $public->filter('.listing-hero__intro strong')->text());
    }

    public function test_a_save_reaches_the_public_page_of_a_town(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inTown('Stäfa', 5);
        $town = (string) $this->connection->fetchOne("SELECT id FROM towns WHERE name = 'Stäfa'");

        $public = $this->saveAndOpen('town.'.$town, '/hochzeitslocations/zuerich/staefa');

        self::assertSame('Heiraten am See', $public->filter('title')->text());
        self::assertSame('Seeufer', $public->filter('.listing-hero__intro strong')->text());
    }

    public function test_a_save_reaches_the_public_page_of_a_building(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->withBuilding($this->inCanton(Canton::ZH, 5), 'castle');

        $public = $this->saveAndOpen('building.castle', '/hochzeitslocations/schloss');

        self::assertSame('Heiraten am See', $public->filter('title')->text());
        self::assertSame('Seeufer', $public->filter('.listing-hero__intro strong')->text());
    }

    public function test_clearing_every_field_deletes_the_rows_and_brings_the_default_back(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inCanton(Canton::ZH, 5);
        $default = $this->client->request('GET', '/hochzeitslocations/zuerich')->filter('title')->text();
        $this->client->submit($this->form(), ['landing_texts[locales][de][title]' => 'Heiraten im Kanton Zürich', 'landing_texts[locales][de][intro]' => 'Am See.']);
        self::assertSame('Heiraten im Kanton Zürich', $this->client->request('GET', '/hochzeitslocations/zuerich')->filter('title')->text());

        $this->client->submit($this->form(), [
            'landing_texts[locales][de][intro]' => '',
            'landing_texts[locales][de][title]' => '   ',
            'landing_texts[locales][de][description]' => '',
        ]);

        self::assertResponseStatusCodeSame(303);
        self::assertSame([], $this->bodies());
        $public = $this->client->request('GET', '/hochzeitslocations/zuerich');
        self::assertSame($default, $public->filter('title')->text());
        self::assertStringContainsString('vergleiche Fotos', $public->filter('.listing-hero__intro')->text());
    }

    public function test_a_title_of_sixty_one_characters_is_refused_on_its_field(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inCanton(Canton::ZH, 5);

        $crawler = $this->client->submit($this->form(), ['landing_texts[locales][de][title]' => str_repeat('ü', 60).'😀']);

        self::assertResponseStatusCodeSame(422);
        $field = $crawler->filter('#landing_texts_locales_de_title');
        self::assertSame('true', $field->attr('aria-invalid'));
        self::assertContains('landing_texts_locales_de_title_error', explode(' ', (string) $field->attr('aria-describedby')));
        self::assertSame('Bitte höchstens 60 Zeichen eingeben.', $crawler->filter('#landing_texts_locales_de_title_error')->text());
        self::assertSame(str_repeat('ü', 60).'😀', $field->attr('value'));
        self::assertSame([], $this->bodies());
    }

    public function test_a_nul_is_refused(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inCanton(Canton::ZH, 5);

        $crawler = $this->client->submit($this->form(), ['landing_texts[locales][de][description]' => "Am See\0."]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('#landing_texts_locales_de_description_error'));
        self::assertSame([], $this->bodies());
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
        $this->inCanton(Canton::ZH, 5);

        $this->client->submit($this->form(), ['landing_texts[locales][de][intro]' => 'Am See.', 'landing_texts[version]' => $version]);

        self::assertResponseStatusCodeSame(422);
        $this->assertNothingStored();
    }

    public function test_a_missing_csrf_token_and_an_empty_post_are_a_422_and_change_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inCanton(Canton::ZH, 5);

        $form = $this->form();
        $form->setValues(['landing_texts[locales][de][intro]' => 'Am See.']);
        $form->remove('landing_texts[_token]');
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', self::EDITOR);
        self::assertResponseStatusCodeSame(422);

        $this->assertNothingStored();
    }

    public function test_a_save_stands_when_the_snapshot_refresh_fails(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inCanton(Canton::ZH, 5);
        $form = $this->form();
        $purges = $this->purger()->listings;
        // The refresh waits for this lock and gives up, as it would on a failing database or cache.
        $this->holdAdvisoryLock($this->connection, ContentBlocks::LOCK);
        $this->connection->executeStatement("SET LOCAL lock_timeout = '50ms'");

        $this->client->submit($form, ['landing_texts[locales][de][intro]' => 'Am See.']);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects(self::EDITOR);
        self::assertSame(['landing.canton.zh.intro' => 'Am See.'], $this->bodies());
        self::assertSame($purges + 1, $this->purger()->listings);
    }

    public function test_a_stale_version_is_a_conflict_that_keeps_the_input(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inCanton(Canton::ZH, 5);
        $form = $this->form();
        $this->store('landing.canton.zh.title', 'Von jemand anderem');
        $purges = $this->purger()->listings;

        $crawler = $this->client->submit($form, ['landing_texts[locales][de][intro]' => 'Mein Text.']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('Mein Text.', $crawler->filter('#landing_texts_locales_de_intro')->text());
        self::assertStringContainsString('inzwischen geändert', $crawler->filter('.form-error')->text());
        self::assertSame(self::EDITOR, $crawler->filter('.content-editor__stale a')->attr('href'));
        self::assertSame(['landing.canton.zh.title' => 'Von jemand anderem'], $this->bodies());
        self::assertSame($purges, $this->purger()->listings);
    }

    public function test_an_unchanged_save_dispatches_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inCanton(Canton::ZH, 5);
        $this->store('landing.canton.zh.intro', "Erster Absatz.\n\nZweiter Absatz.");
        $purges = $this->purger()->listings;

        $this->client->submit($this->form());

        self::assertResponseStatusCodeSame(303);
        $crawler = $this->client->followRedirect();
        self::assertSame('Keine Änderungen an «Hochzeitslocations im Kanton Zürich».', $crawler->filter('.toast')->text());
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'landing_page'"));
        self::assertSame($purges, $this->purger()->listings);
    }

    public function test_an_unknown_or_venueless_page_is_not_found(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inCanton(Canton::ZH, 5);

        foreach (['canton.be', 'town.0199f0d0-0000-7000-8000-000000000001', 'building.castle', 'region.zh'] as $landing) {
            $this->client->request('GET', '/admin/landingpages/'.$landing);
            self::assertResponseStatusCodeSame(404, $landing);
        }
        $this->client->request('POST', '/admin/landingpages/canton.be');
        self::assertResponseStatusCodeSame(404);
    }

    private function form(string $editor = self::EDITOR): Form
    {
        return $this->client->request('GET', $editor)->filter('form#landing-texts-form')->form();
    }

    private function saveAndOpen(string $landing, string $publicPath): Crawler
    {
        $this->client->submit($this->form('/admin/landingpages/'.$landing), [
            'landing_texts[locales][de][intro]' => 'Am **Seeufer** feiern.',
            'landing_texts[locales][de][title]' => 'Heiraten am See',
        ]);
        self::assertResponseStatusCodeSame(303);

        $public = $this->client->request('GET', $publicPath);
        self::assertResponseIsSuccessful();

        return $public;
    }

    private function assertNothingStored(): void
    {
        self::assertSame([], $this->bodies());
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'landing_page'"));
    }

    private function store(string $key, string $body): void
    {
        $this->connection->insert('content_blocks', ['key' => $key, 'locale' => 'de', 'body_markdown' => $body, 'updated_at' => '2026-10-30 09:00:00+00']);
    }

    /**
     * @return array<string, string>
     */
    private function bodies(): array
    {
        /** @var array<string, string> $bodies */
        $bodies = $this->connection->fetchAllKeyValue(
            "SELECT key, body_markdown FROM content_blocks WHERE key IN (:keys) AND locale = 'de' ORDER BY key",
            ['keys' => self::KEYS],
            ['keys' => ArrayParameterType::STRING],
        );

        return $bodies;
    }

    private function row(Crawler $crawler, string $prefix): Crawler
    {
        return $crawler->filter('#landing-list a[href="/admin/landingpages/'.$prefix.'"]')->closest('tr') ?? throw new \LogicException($prefix);
    }

    /**
     * @return list<string>
     */
    private function pills(Crawler $crawler, string $prefix): array
    {
        return $this->row($crawler, $prefix)->filter('.pill')->each(static fn (Crawler $pill): string => $pill->text());
    }

    /**
     * @return list<string>
     */
    private function prefixes(Crawler $crawler): array
    {
        return $crawler->filter('#landing-list a.landing-list__link')->each(static fn (Crawler $link): string => substr((string) $link->attr('href'), \strlen('/admin/landingpages/')));
    }

    /**
     * @return list<string>
     */
    private function types(Crawler $crawler): array
    {
        return array_values(array_unique(array_map(static fn (string $prefix): string => explode('.', $prefix)[0], $this->prefixes($crawler))));
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);

        return $purger;
    }
}
