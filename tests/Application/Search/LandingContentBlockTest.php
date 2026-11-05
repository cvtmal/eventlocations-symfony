<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Geo\ValueObject\Canton;
use App\Search\Extension\ListingIntroBlock;
use App\Search\ReadModel\ListingQuery;
use App\Shared\Extension\FooterNoteBlock;
use App\Shared\ReadModel\ContentBlocks;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Cms\ResetsContentBlocks;
use App\Tests\Support\Search\LandingFixtures;
use App\Tests\Support\Venues\DetailVenues;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * Staff texts on the public pages (#26): a landing page's intro replaces #17's sentence, the
 * plain listing has its own intro on its clean page 1 (P12), and every page has the footer note
 * once the snapshot is warm. Without a row, or with a body that renders no visible text, each page
 * shows #17's text. The pages stay public and session-free (S10), on a cold pool too. Rows are
 * inserted with SQL inside the test's transaction and the snapshot refreshed, as a CMS save does
 * after its commit.
 */
final class LandingContentBlockTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use LandingFixtures;
    use ResetsContentBlocks;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->resetContentBlocks();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        $this->resetContentBlocks();
        parent::tearDown();
    }

    public function test_without_a_row_every_landing_page_shows_its_catalogue_intro(): void
    {
        $this->withBuilding($this->inTown('Stäfa', 5), 'castle');

        self::assertSame(
            '5 Hochzeitslocations im Kanton Zürich: Vergleichen Sie Fotos und Platz für Gäste und fragen Sie direkt bei der Location an.',
            $this->intro('/hochzeitslocations/zuerich')->filter('p')->text(),
        );
        self::assertSame(
            '5 Hochzeitslocations in Stäfa: Vergleichen Sie Fotos und Platz für Gäste und fragen Sie direkt bei der Location an.',
            $this->intro('/hochzeitslocations/zuerich/staefa')->filter('p')->text(),
        );
        self::assertSame(
            '<p>5 Locations für Ihre Hochzeit im Schloss: Vergleichen Sie Fotos und Platz für Gäste und fragen Sie direkt an.</p>',
            trim($this->intro('/hochzeitslocations/schloss')->html()),
        );
        $this->assertPublicAndSessionFree();
    }

    public function test_a_stored_intro_replaces_the_sentence_as_markdown(): void
    {
        $this->inCanton(Canton::ZH, 5);
        $this->store(['landing.canton.zh.intro' => "Am **Zürichsee** feiern.\n\nZweiter Absatz."]);

        $intro = $this->intro('/hochzeitslocations/zuerich');

        self::assertSame(['Am Zürichsee feiern.', 'Zweiter Absatz.'], $intro->filter('p')->each(static fn (Crawler $p): string => $p->text()));
        self::assertSame('Zürichsee', $intro->filter('strong')->text());
        self::assertStringNotContainsString('Vergleichen Sie Fotos', $intro->text());
        $this->assertPublicAndSessionFree();
    }

    public function test_script_and_javascript_links_in_a_stored_intro_are_neutralised(): void
    {
        $this->inCanton(Canton::ZH, 5);
        $this->store(['landing.canton.zh.intro' => "<script>alert(1)</script>\n\n[Klick](javascript:alert(1)) und <img src=x onerror=alert(1)>"]);

        $html = $this->intro('/hochzeitslocations/zuerich')->html();

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertCount(0, $this->client->getCrawler()->filter('.listing-hero__intro a[href]'), 'The unsafe link keeps its text, not its target.');
    }

    public function test_the_listing_intro_shows_on_the_clean_first_page_only(): void
    {
        $this->inCanton(Canton::BE, ListingQuery::PER_PAGE + 1);
        $this->store([ListingIntroBlock::KEY => 'Finde die **passende** Location.']);

        self::assertSame('Finde die passende Location.', $this->intro('/hochzeitslocations')->text());
        $this->assertPublicAndSessionFree();

        foreach (['/hochzeitslocations?guests=10', '/hochzeitslocations?page=2', '/hochzeitslocations?canton=be'] as $uri) {
            $crawler = $this->client->request('GET', $uri);
            self::assertResponseIsSuccessful($uri);
            self::assertCount(0, $crawler->filter('.listing-hero__intro'), $uri);
        }
    }

    public function test_without_a_row_the_listing_has_no_intro(): void
    {
        $this->inCanton(Canton::BE, 1);

        $crawler = $this->client->request('GET', '/hochzeitslocations');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.listing-hero__intro'));
        self::assertCount(0, $crawler->filter('.site-footer__note'));
    }

    public function test_the_footer_note_shows_on_landing_and_detail_pages_once_the_snapshot_is_warm(): void
    {
        $this->inCanton(Canton::ZH, 5);
        DetailVenues::minimal();
        $this->store([FooterNoteBlock::KEY => 'Alle Angaben [ohne Gewähr](/datenschutz).']);

        foreach (['/hochzeitslocations/zuerich', '/hochzeitslocation/'.DetailVenues::MINIMAL_SLUG] as $uri) {
            $crawler = $this->client->request('GET', $uri);
            self::assertResponseIsSuccessful($uri);
            $note = $crawler->filter('footer.site-footer .site-footer__note');
            self::assertCount(1, $note, $uri);
            self::assertSame('Alle Angaben ohne Gewähr.', $note->text(), $uri);
            self::assertSame('/datenschutz', $note->filter('a')->attr('href'), $uri);
            $this->assertPublicAndSessionFree();
        }
    }

    public function test_a_heading_in_a_stored_intro_leaves_the_page_one_h1(): void
    {
        $this->inCanton(Canton::ZH, 5);
        $this->store(['landing.canton.zh.intro' => "# Am Zürichsee\n\nFeiern."]);

        $intro = $this->intro('/hochzeitslocations/zuerich');

        self::assertSame('Am Zürichsee', $intro->filter('h2')->text());
        self::assertCount(1, $this->client->getCrawler()->filter('h1'));
        self::assertSame('Hochzeitslocations im Kanton Zürich', $this->client->getCrawler()->filter('h1')->text());
    }

    public function test_a_code_block_showing_an_h1_stays_text(): void
    {
        $this->inCanton(Canton::ZH, 5);
        $this->store(['landing.canton.zh.intro' => "```\n<h1>Titel</h1>\n```"]);

        $intro = $this->intro('/hochzeitslocations/zuerich');

        self::assertSame('<h1>Titel</h1>', $intro->filter('code')->text());
        self::assertCount(1, $this->client->getCrawler()->filter('h1'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bodiesWithoutVisibleText(): iterable
    {
        yield 'a lone link reference' => ['[a]: /b'];
        yield 'only NBSP' => ["\u{00A0}"];
    }

    #[DataProvider('bodiesWithoutVisibleText')]
    public function test_a_body_without_visible_text_falls_back_to_the_sentence(string $body): void
    {
        $this->inCanton(Canton::ZH, 5);
        $this->store(['landing.canton.zh.intro' => $body]);

        self::assertStringContainsString('Vergleichen Sie Fotos', $this->intro('/hochzeitslocations/zuerich')->text());
    }

    public function test_public_pages_on_a_cold_pool_stay_public_and_set_no_cookie(): void
    {
        $this->inCanton(Canton::ZH, 5);
        DetailVenues::minimal();
        $this->connection->insert('content_blocks', ['key' => 'landing.canton.zh.intro', 'locale' => 'de', 'body_markdown' => 'Am See.', 'updated_at' => '2026-10-31 08:00:00+00']);
        $this->connection->insert('content_blocks', ['key' => ListingIntroBlock::KEY, 'locale' => 'de', 'body_markdown' => 'Willkommen.', 'updated_at' => '2026-10-31 08:00:00+00']);
        $pool = self::getContainer()->get('cache.content_blocks');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        foreach (['/hochzeitslocations' => 'Willkommen.', '/hochzeitslocations/zuerich' => 'Am See.'] as $uri => $intro) {
            $this->resetContentBlocks();
            self::assertFalse($pool->getItem(ContentBlocks::ITEM)->isHit(), $uri);

            self::assertSame($intro, $this->intro($uri)->text());
            $this->assertPublicAndSessionFree();
            self::assertTrue($pool->getItem(ContentBlocks::ITEM)->isHit(), $uri);
        }

        $this->resetContentBlocks();
        $this->client->request('GET', '/hochzeitslocation/'.DetailVenues::MINIMAL_SLUG);
        self::assertResponseIsSuccessful();
        $this->assertPublicAndSessionFree();
        self::assertFalse($pool->getItem(ContentBlocks::ITEM)->isHit(), 'A page that only peeks stores nothing.');
    }

    private function intro(string $uri): Crawler
    {
        $crawler = $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful($uri);
        $intro = $crawler->filter('div.listing-hero__intro');
        self::assertCount(1, $intro, $uri);

        return $intro;
    }

    /**
     * @param array<string, string> $bodies key → Markdown, locale `de`
     */
    private function store(array $bodies): void
    {
        foreach ($bodies as $key => $body) {
            $this->connection->insert('content_blocks', ['key' => $key, 'locale' => 'de', 'body_markdown' => $body, 'updated_at' => '2026-10-31 08:00:00+00']);
        }

        $blocks = self::getContainer()->get(ContentBlocks::class);
        self::assertInstanceOf(ContentBlocks::class, $blocks);
        $blocks->refresh();
    }

    private function assertPublicAndSessionFree(): void
    {
        $response = $this->client->getResponse();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        // #19: listings until Zurich midnight, the detail page a day (PublicCache).
        self::assertMatchesRegularExpression('/\bs-maxage=\d+\b/', $cacheControl);
        self::assertStringContainsString('stale-while-revalidate=86400', $cacheControl);
        self::assertStringContainsString('stale-if-error=86400', $cacheControl);
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertSame([], $response->headers->getCookies());
        self::assertSame([], $this->client->getCookieJar()->all());
        self::assertFalse($this->client->getRequest()->hasPreviousSession());
    }
}
