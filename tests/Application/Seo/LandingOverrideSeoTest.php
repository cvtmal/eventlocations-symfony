<?php

declare(strict_types=1);

namespace App\Tests\Application\Seo;

use App\Geo\ValueObject\Canton;
use App\Search\ReadModel\ListingQuery;
use App\Shared\ReadModel\ContentBlocks;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Cms\ResetsContentBlocks;
use App\Tests\Support\Search\LandingFixtures;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * Staff titles and descriptions of a landing page (#26, P5): they replace #17's on page 1,
 * filtered or not, query-driven or not, and a numbered page keeps #17's `title_page*` chain.
 * Without a row, or with one of only Unicode spaces, the page is #17's.
 */
final class LandingOverrideSeoTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use LandingFixtures;
    use ResetsContentBlocks;

    private const string TITLE = 'Hochzeit am Zürichsee: unsere Lieblingsorte';
    private const string DESCRIPTION = 'Schlösser, Seeufer und Scheunen im Kanton Zürich: unsere Auswahl für Ihre Hochzeit.';

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

    public function test_a_stored_title_and_description_replace_the_first_pages(): void
    {
        $this->inCanton(Canton::ZH, ListingQuery::PER_PAGE + 1);
        $before = $this->get('/hochzeitslocations/zuerich');
        $defaultTitle = $before->filter('title')->text();
        $defaultDescription = $this->description($before);
        $pageTwo = $this->get('/hochzeitslocations/zuerich?page=2')->filter('title')->text();

        $this->store(['landing.canton.zh.title' => self::TITLE, 'landing.canton.zh.description' => self::DESCRIPTION]);

        $crawler = $this->get('/hochzeitslocations/zuerich');
        self::assertSame(self::TITLE, $crawler->filter('title')->text());
        self::assertSame(self::DESCRIPTION, $this->description($crawler));
        self::assertSame(self::TITLE, $crawler->filter('meta[property="og:title"]')->attr('content'));

        $filtered = $this->get('/hochzeitslocations/zuerich?guests=10');
        self::assertSame(self::TITLE, $filtered->filter('title')->text(), 'A filtered page 1 keeps the staff title.');

        $numbered = $this->get('/hochzeitslocations/zuerich?page=2');
        self::assertSame($pageTwo, $numbered->filter('title')->text(), 'Page 2 keeps #17\'s numbered title.');
        self::assertStringContainsString('Seite 2', $pageTwo);
        self::assertNotSame(self::DESCRIPTION, $this->description($numbered));

        self::assertNotSame(self::TITLE, $defaultTitle);
        self::assertNotSame(self::DESCRIPTION, $defaultDescription);
    }

    public function test_a_long_description_is_fitted_like_the_catalogue_one(): void
    {
        $this->inCanton(Canton::ZH, 5);
        $long = str_repeat('Feiern am See mit Blick auf die Berge und viel Platz für Gäste. ', 4);
        $this->store(['landing.canton.zh.description' => $long]);

        $description = $this->description($this->get('/hochzeitslocations/zuerich'));

        self::assertLessThanOrEqual(155, mb_strlen($description));
        self::assertStringStartsWith('Feiern am See mit Blick auf die Berge', $description);
    }

    public function test_without_a_row_the_page_is_unchanged(): void
    {
        $this->inCanton(Canton::ZH, 5);
        $this->inCanton(Canton::BE, 5);
        $before = (string) $this->client->request('GET', '/hochzeitslocations/zuerich')->html();

        // Another page's and another locale's rows change nothing here (P7).
        $this->store(['landing.canton.be.title' => self::TITLE, 'landing.canton.be.intro' => 'Bern.']);
        $this->connection->insert('content_blocks', ['key' => 'landing.canton.zh.title', 'locale' => 'fr', 'body_markdown' => 'Mariage à Zurich', 'updated_at' => '2026-10-31 08:00:00+00']);
        $this->refresh();

        self::assertSame($before, (string) $this->client->request('GET', '/hochzeitslocations/zuerich')->html());
        self::assertSame(self::TITLE, $this->get('/hochzeitslocations/bern')->filter('title')->text());
    }

    public function test_a_query_driven_landing_carries_the_stored_title_and_stays_noindex(): void
    {
        $this->inCanton(Canton::ZH, 5);
        $this->store(['landing.canton.zh.title' => self::TITLE]);

        $crawler = $this->get('/hochzeitslocations?canton=zh');

        self::assertSame(self::TITLE, $crawler->filter('title')->text());
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame('http://localhost/hochzeitslocations/zuerich', $crawler->filter('link[rel="canonical"]')->attr('href'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blanks(): iterable
    {
        yield 'NBSP' => ["\u{00A0}"];
        yield 'NBSP around a blank' => ["\u{00A0} \u{202F}"];
    }

    #[DataProvider('blanks')]
    public function test_a_title_or_description_of_unicode_spaces_falls_back_to_the_catalogue(string $blank): void
    {
        $this->inCanton(Canton::ZH, 5);
        $before = $this->get('/hochzeitslocations/zuerich');
        $title = $before->filter('title')->text();
        $description = $this->description($before);

        $this->store(['landing.canton.zh.title' => $blank, 'landing.canton.zh.description' => $blank]);

        $crawler = $this->get('/hochzeitslocations/zuerich');
        self::assertSame($title, $crawler->filter('title')->text());
        self::assertSame($description, $this->description($crawler));
    }

    private function get(string $uri): Crawler
    {
        $crawler = $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful($uri);

        return $crawler;
    }

    private function description(Crawler $crawler): string
    {
        return (string) $crawler->filter('meta[name="description"]')->attr('content');
    }

    /**
     * @param array<string, string> $bodies key → text, locale `de`
     */
    private function store(array $bodies): void
    {
        foreach ($bodies as $key => $body) {
            $this->connection->insert('content_blocks', ['key' => $key, 'locale' => 'de', 'body_markdown' => $body, 'updated_at' => '2026-10-31 08:00:00+00']);
        }
        $this->refresh();
    }

    private function refresh(): void
    {
        $blocks = self::getContainer()->get(ContentBlocks::class);
        self::assertInstanceOf(ContentBlocks::class, $blocks);
        $blocks->refresh();
    }
}
