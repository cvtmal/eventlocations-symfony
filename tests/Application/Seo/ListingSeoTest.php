<?php

declare(strict_types=1);

namespace App\Tests\Application\Seo;

use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\Entity\Town;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Search\LandingFixtures;
use App\Venues\Entity\Venue;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * SEO-1, SEO-2 and SEO-4 on the listing and its landing pages (#17): one rule from the parsed
 * criteria for every URL shape. The 49 venues in the town Zürich make the canton page ZH and the
 * town page Zürich live (threshold 5), so a ZH filter state canonicalises to its landing page.
 */
final class ListingSeoTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use LandingFixtures;

    private const string CLEAN = 'http://localhost/hochzeitslocations';
    private const string TITLE = 'Hochzeitslocations in der Schweiz | deine-eventlocation.ch';
    private const string PAGE_2_TITLE = 'Hochzeitslocations Schweiz – Seite 2';
    private const string ZURICH = 'http://localhost/hochzeitslocations/zuerich';
    private const string ZURICH_TITLE = 'Hochzeitslocations im Kanton Zürich | deine-eventlocation.ch';
    private const string TOWN = 'http://localhost/hochzeitslocations/zuerich/zuerich';
    private const string TOWN_TITLE = '49 Hochzeitslocations in Zürich | deine-eventlocation.ch';
    private const string CASTLE = 'http://localhost/hochzeitslocations/schloss';
    private const string CASTLE_TITLE = 'Hochzeit im Schloss: 49 Locations | deine-eventlocation.ch';

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
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_every_url_shape_has_its_canonical_robots_hreflang_and_title(): void
    {
        $this->venues(49);
        $town = $this->town('Zürich')->id();

        // [url, canonical, noindex, hreflang links, title]
        $shapes = [
            ['/hochzeitslocations', self::CLEAN, false, 2, self::TITLE],
            ['/hochzeitslocations?', self::CLEAN, false, 2, self::TITLE],
            ['/hochzeitslocations?canton=&place=&radius=5', self::CLEAN, false, 2, self::TITLE],
            ['/hochzeitslocations?page=1', self::CLEAN, false, 2, self::TITLE],
            ['/hochzeitslocations?page=abc', self::CLEAN, false, 2, self::TITLE],
            ['/hochzeitslocations?utm_source=x', self::CLEAN, false, 2, self::TITLE],
            ['/hochzeitslocations?page=2', self::CLEAN.'?page=2', false, 2, self::PAGE_2_TITLE],
            // A radius other than 0 is no town page, and no canton is named: the plain listing.
            ['/hochzeitslocations?place='.$town.'&radius=5', self::CLEAN, true, 0, self::TITLE],
            // A live canton in the query belongs on its landing page (P5): noindex, canonical there.
            ['/hochzeitslocations?guests=100&canton=zh', self::ZURICH, true, 0, self::ZURICH_TITLE],
            ['/hochzeitslocations?setting[]=lake', self::CLEAN, true, 0, self::TITLE],
            ['/hochzeitslocations?canton=zh&page=2', self::ZURICH, true, 0, self::ZURICH_TITLE],
        ];

        foreach ($shapes as [$url, $canonical, $noindex, $hreflang, $title]) {
            $this->assertShape($url, $canonical, $noindex, $hreflang, $title);
        }

        $this->client->request('GET', '/hochzeitslocations?page=99');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_every_landing_url_shape_has_its_canonical_robots_hreflang_and_title(): void
    {
        $this->withBuilding($this->venues(49), 'castle');
        $town = $this->town('Zürich')->id();

        // [url, canonical, noindex, hreflang links, title]
        $shapes = [
            ['/hochzeitslocations/zuerich', self::ZURICH, false, 2, self::ZURICH_TITLE],
            ['/hochzeitslocations/zuerich?page=1', self::ZURICH, false, 2, self::ZURICH_TITLE],
            ['/hochzeitslocations/zuerich?utm_source=x', self::ZURICH, false, 2, self::ZURICH_TITLE],
            ['/hochzeitslocations/zuerich?page=2', self::ZURICH.'?page=2', false, 2, 'Hochzeitslocations Zürich – Seite 2 | deine-eventlocation.ch'],
            ['/hochzeitslocations/zuerich?guests=100', self::ZURICH, true, 0, self::ZURICH_TITLE],
            ['/hochzeitslocations/zuerich?building[]=castle', self::ZURICH, true, 0, self::ZURICH_TITLE],
            // The path wins over the query (P3), so this is the clean canton page.
            ['/hochzeitslocations/zuerich?canton=be', self::ZURICH, false, 2, self::ZURICH_TITLE],
            ['/hochzeitslocations?canton=zh', self::ZURICH, true, 0, self::ZURICH_TITLE],

            ['/hochzeitslocations/zuerich/zuerich', self::TOWN, false, 2, self::TOWN_TITLE],
            ['/hochzeitslocations/zuerich/zuerich?page=2', self::TOWN.'?page=2', false, 2, 'Hochzeitslocations Zürich – Seite 2 | deine-eventlocation.ch'],
            ['/hochzeitslocations/zuerich/zuerich?guests=100', self::TOWN, true, 0, self::TOWN_TITLE],
            ['/hochzeitslocations?place='.$town.'&radius=0', self::TOWN, true, 0, self::TOWN_TITLE],
            ['/hochzeitslocations/zuerich?place='.$town.'&radius=0', self::TOWN, true, 0, self::TOWN_TITLE],

            ['/hochzeitslocations/schloss', self::CASTLE, false, 2, self::CASTLE_TITLE],
            ['/hochzeitslocations/schloss?page=2', self::CASTLE.'?page=2', false, 2, 'Hochzeit im Schloss – Seite 2 | deine-eventlocation.ch'],
            ['/hochzeitslocations/schloss?guests=100', self::CASTLE, true, 0, self::CASTLE_TITLE],
            ['/hochzeitslocations?building[]=castle', self::CASTLE, true, 0, self::CASTLE_TITLE],
            // A canton outranks a building: the facet the canton page cannot hold stays in the query.
            ['/hochzeitslocations/schloss?canton=zh', self::ZURICH, true, 0, self::ZURICH_TITLE],
        ];

        foreach ($shapes as [$url, $canonical, $noindex, $hreflang, $title]) {
            $this->assertShape($url, $canonical, $noindex, $hreflang, $title);
        }
    }

    public function test_an_indexable_landing_page_lists_its_venues_and_a_filtered_one_does_not(): void
    {
        $this->withBuilding($this->venues(49), 'castle');

        foreach (['/hochzeitslocations/zuerich', '/hochzeitslocations/zuerich/zuerich', '/hochzeitslocations/schloss'] as $url) {
            $items = $this->itemList($this->get($url));
            self::assertCount(48, $items, $url);
            self::assertSame(range(1, 48), array_column($items, 'position'), $url);
            self::assertSame([49], array_column($this->itemList($this->get($url.'?page=2')), 'position'), $url);
        }

        foreach (['/hochzeitslocations/zuerich?guests=100', '/hochzeitslocations?canton=zh', '/hochzeitslocations/zuerich/zuerich?guests=100', '/hochzeitslocations/schloss?guests=100'] as $url) {
            self::assertSame([], $this->blocks($this->get($url), 'ItemList'), $url);
        }
    }

    public function test_landing_titles_and_descriptions_fit_and_differ_by_page(): void
    {
        $this->withBuilding($this->venues(49), 'castle');

        foreach (['/hochzeitslocations/zuerich', '/hochzeitslocations/zuerich/zuerich', '/hochzeitslocations/schloss'] as $url) {
            $first = $this->get($url);
            $second = $this->get($url.'?page=2');

            self::assertNotSame($first->filter('title')->text(), $second->filter('title')->text(), $url);
            foreach ([$first, $second] as $crawler) {
                self::assertLessThanOrEqual(60, mb_strlen($crawler->filter('title')->text()), $url);
                $description = (string) $crawler->filter('meta[name="description"]')->attr('content');
                self::assertNotSame('', $description, $url);
                self::assertLessThanOrEqual(155, mb_strlen($description), $url);
                self::assertCount(1, $crawler->filter('h1'), $url);
            }
        }
    }

    public function test_page_one_and_two_have_their_own_title_and_description(): void
    {
        $this->venues(49);

        $first = $this->get('/hochzeitslocations');
        $second = $this->get('/hochzeitslocations?page=2');

        self::assertNotSame($first->filter('title')->text(), $second->filter('title')->text());
        self::assertNotSame(
            $first->filter('meta[name="description"]')->attr('content'),
            $second->filter('meta[name="description"]')->attr('content'),
        );
        foreach ([$first, $second] as $crawler) {
            self::assertLessThanOrEqual(60, mb_strlen($crawler->filter('title')->text()));
            self::assertLessThanOrEqual(155, mb_strlen((string) $crawler->filter('meta[name="description"]')->attr('content')));
            self::assertCount(1, $crawler->filter('h1'));
        }
    }

    public function test_the_first_card_photo_is_the_share_image(): void
    {
        $venue = $this->venues(1)[0];
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);

        $crawler = $this->get('/hochzeitslocations');

        self::assertSame('website', $crawler->filter('meta[property="og:type"]')->attr('content'));
        self::assertMatchesRegularExpression('#^http://localhost/media/\d+/[0-9a-f-]+-1280\.jpg$#', (string) $crawler->filter('meta[property="og:image"]')->attr('content'));
        self::assertSame('1280', $crawler->filter('meta[property="og:image:width"]')->attr('content'));
        self::assertSame('854', $crawler->filter('meta[property="og:image:height"]')->attr('content'));
        self::assertSame('summary_large_image', $crawler->filter('meta[name="twitter:card"]')->attr('content'));
        self::assertSame($crawler->filter('meta[property="og:image"]')->attr('content'), $crawler->filter('meta[name="twitter:image"]')->attr('content'));
        self::assertSame($crawler->filter('title')->text(), $crawler->filter('meta[name="twitter:title"]')->attr('content'));
        self::assertSame($crawler->filter('meta[name="description"]')->attr('content'), $crawler->filter('meta[name="twitter:description"]')->attr('content'));
        self::assertSame('de_CH', $crawler->filter('meta[property="og:locale"]')->attr('content'));
        self::assertSame('deine-eventlocation.ch', $crawler->filter('meta[property="og:site_name"]')->attr('content'));
    }

    public function test_without_a_photo_there_is_no_image_and_a_small_card(): void
    {
        $this->venues(1);

        $crawler = $this->get('/hochzeitslocations');

        self::assertCount(0, $crawler->filter('meta[property="og:image"]'));
        self::assertCount(0, $crawler->filter('meta[name="twitter:image"]'));
        self::assertSame('summary', $crawler->filter('meta[name="twitter:card"]')->attr('content'));
    }

    public function test_an_indexable_page_lists_its_venues_with_running_positions(): void
    {
        $this->venues(49);

        $first = $this->itemList($this->get('/hochzeitslocations'));
        $second = $this->itemList($this->get('/hochzeitslocations?page=2'));

        self::assertCount(48, $first);
        self::assertSame(range(1, 48), array_column($first, 'position'));
        self::assertStringStartsWith('http://localhost/hochzeitslocation/', $first[0]['url']);
        self::assertNotSame('', $first[0]['name']);
        self::assertSame([49], array_column($second, 'position'));
    }

    public function test_a_filter_page_has_no_item_list(): void
    {
        $this->venues(1);

        self::assertSame([], $this->blocks($this->get('/hochzeitslocations?canton=zh'), 'ItemList'));
    }

    public function test_an_empty_listing_has_no_item_list(): void
    {
        self::assertSame([], $this->blocks($this->get('/hochzeitslocations'), 'ItemList'));
    }

    public function test_every_page_has_one_site_graph(): void
    {
        $this->venues(1);

        $crawler = $this->get('/hochzeitslocations');

        $graphs = array_values(array_filter(
            $this->jsonLd($crawler),
            static fn (array $block): bool => isset($block['@graph']),
        ));
        self::assertCount(1, $graphs);
        self::assertIsArray($graphs[0]['@graph']);
        self::assertSame(['WebSite', 'Organization'], array_column($graphs[0]['@graph'], '@type'));
        self::assertSame('http://localhost/', $graphs[0]['@graph'][0]['url']);
        self::assertSame('Pick n Roll GmbH', $graphs[0]['@graph'][1]['legalName']);
        self::assertSame('8006', $graphs[0]['@graph'][1]['address']['postalCode']);
    }

    private function assertShape(string $url, string $canonical, bool $noindex, int $hreflang, string $title): void
    {
        $crawler = $this->get($url);

        self::assertSame($canonical, $crawler->filter('link[rel="canonical"]')->attr('href'), $url);
        self::assertSame($noindex ? ['noindex, follow'] : [], $crawler->filter('meta[name="robots"]')->each(static fn (Crawler $meta): ?string => $meta->attr('content')), $url);
        self::assertCount($hreflang, $crawler->filter('link[hreflang]'), $url);
        self::assertSame($title, $crawler->filter('title')->text(), $url);
        self::assertSame($canonical, $crawler->filter('meta[property="og:url"]')->attr('content'), $url);
        self::assertSame('website', $crawler->filter('meta[property="og:type"]')->attr('content'), $url);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jsonLd(Crawler $crawler): array
    {
        return $crawler->filter('script[type="application/ld+json"]')->each(static function (Crawler $script): array {
            $data = json_decode($script->text(null, false), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);

            /* @var array<string, mixed> */
            return $data;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function blocks(Crawler $crawler, string $type): array
    {
        return array_values(array_filter($this->jsonLd($crawler), static fn (array $block): bool => $type === ($block['@type'] ?? null)));
    }

    /**
     * @return list<array{position: int, url: string, name: string}>
     */
    private function itemList(Crawler $crawler): array
    {
        $lists = $this->blocks($crawler, 'ItemList');
        self::assertCount(1, $lists);
        /** @var list<array{position: int, url: string, name: string}> $items */
        $items = $lists[0]['itemListElement'];

        return $items;
    }

    private function get(string $uri): Crawler
    {
        $crawler = $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful($uri);

        return $crawler;
    }

    /**
     * @return list<Venue>
     */
    private function venues(int $count): array
    {
        $town = $this->town('Zürich');
        $venues = [];
        for ($i = 0; $i < $count; ++$i) {
            $venue = VenueFactory::createOne(['town' => $town]);
            VenueTranslationFactory::createOne(['venue' => $venue]);
            $venues[] = $venue;
        }

        return $venues;
    }

    private function town(string $name): Town
    {
        $town = repository(Town::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Town::class, $town);

        return $town;
    }
}
