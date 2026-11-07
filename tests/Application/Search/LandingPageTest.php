<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\Entity\Canton as CantonRow;
use App\Geo\ValueObject\Canton;
use App\Search\ReadModel\LandingPages;
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
 * The landing pages (#17): `/eventlokale/{canton}`, `…/{canton}/{town}` and `…/{building}`,
 * switched on by data at `%landing.min_venues%` (5 in test). The client shares the test's
 * connection (no reboot), so the venues created here are visible to the request and rolled
 * back afterwards; the kernel resets LandingPages' memo between requests.
 */
final class LandingPageTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use LandingFixtures;

    /** D3: the cantons with at least five venues at launch. */
    private const LAUNCH_CANTONS = [
        Canton::ZH, Canton::BE, Canton::LU, Canton::SG, Canton::TG, Canton::AG, Canton::SZ,
        Canton::VS, Canton::SO, Canton::GR, Canton::OW, Canton::NW, Canton::SH,
    ];

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

    public function test_a_canton_page_renders_its_heading_intro_breadcrumb_and_cards(): void
    {
        $zurich = $this->names($this->inCanton(Canton::ZH, 5));
        $this->inCanton(Canton::BE, 5);

        $crawler = $this->get('/eventlokale/zuerich');

        self::assertSelectorTextSame('.listing-hero__title', 'Hochzeitslocations im Kanton Zürich');
        self::assertCount(1, $crawler->filter('h1'));
        self::assertSame(
            '5 Hochzeitslocations im Kanton Zürich: Vergleiche Fotos und Platz für Gäste und frag direkt bei der Location an.',
            $crawler->filter('.listing-hero__intro > p')->text(),
        );
        self::assertSame($zurich, $this->cardNames($crawler));

        $items = $crawler->filter('nav.breadcrumb li.breadcrumb__item');
        self::assertSame(['Hochzeitslocations', 'Kanton Zürich'], $items->each(static fn (Crawler $item): string => $item->text()));
        self::assertSame('http://localhost/eventlokale', $items->eq(0)->filter('a')->attr('href'));
        self::assertCount(0, $items->eq(1)->filter('a'));
        self::assertSame('page', $items->eq(1)->filter('[aria-current]')->attr('aria-current'));
        self::assertSame('Navigationspfad', $crawler->filter('nav.breadcrumb')->attr('aria-label'));

        self::assertSame([
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Hochzeitslocations', 'item' => 'http://localhost/eventlokale'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Kanton Zürich'],
        ], $this->breadcrumbJsonLd($crawler));

        $this->assertPublicAndCookieFree();
    }

    public function test_a_canton_under_the_threshold_an_unknown_slug_and_a_page_past_the_last_are_404(): void
    {
        $this->inCanton(Canton::ZH, 5);
        $this->inCanton(Canton::TI, 4);

        foreach (['/eventlokale/tessin', '/eventlokale/atlantis', '/eventlokale/zuerich?page=2'] as $uri) {
            $this->client->request('GET', $uri);
            self::assertResponseStatusCodeSame(404, $uri);
            $this->assertPublicAndCookieFree();
        }
    }

    public function test_the_launch_data_switches_on_exactly_the_thirteen_canton_pages(): void
    {
        foreach (self::LAUNCH_CANTONS as $canton) {
            $this->inCanton($canton, 5);
        }
        foreach ([[Canton::TI, 3], [Canton::GL, 2], [Canton::ZG, 2], [Canton::FR, 2], [Canton::UR, 1]] as [$canton, $count]) {
            $this->inCanton($canton, $count);
        }

        $live = array_map(static fn (Canton $canton): string => $canton->value, self::LAUNCH_CANTONS);
        $answered = [];
        foreach ($this->connection->fetchAllKeyValue('SELECT code, slug FROM cantons ORDER BY code') as $code => $slug) {
            $this->client->request('GET', '/eventlokale/'.$slug);
            $answered[$code] = $this->client->getResponse()->getStatusCode();
        }
        self::assertCount(27, $answered);
        foreach ($answered as $code => $status) {
            self::assertSame(\in_array($code, $live, true) ? 200 : 404, $status, (string) $code);
        }

        $pages = $this->landingPages();
        self::assertCount(13, $pages->cantons());
        self::assertSame([], $pages->towns());
        self::assertSame([], $pages->buildings());
        foreach (['/eventlokale/schloss', '/eventlokale/hotel', '/eventlokale/zuerich/zuerich', '/eventlokale/bern/bern'] as $uri) {
            $this->client->request('GET', $uri);
            self::assertResponseStatusCodeSame(404, $uri);
        }
    }

    public function test_a_fifth_venue_switches_a_canton_page_on(): void
    {
        $this->inCanton(Canton::TI, 4);
        $this->client->request('GET', '/eventlokale/tessin');
        self::assertResponseStatusCodeSame(404);

        $this->inCanton(Canton::TI, 1);

        $this->get('/eventlokale/tessin');
        self::assertSelectorTextSame('.listing-hero__title', 'Hochzeitslocations im Kanton Tessin');
    }

    public function test_liechtenstein_gets_its_page_at_five_venues_and_no_kanton(): void
    {
        $this->inCanton(Canton::LI, 4);
        $this->client->request('GET', '/eventlokale/liechtenstein');
        self::assertResponseStatusCodeSame(404, 'Four venues stay under the threshold (#46, owner answer C).');

        $this->inCanton(Canton::LI, 1);

        $crawler = $this->get('/eventlokale/liechtenstein');
        self::assertSelectorTextSame('.listing-hero__title', 'Hochzeitslocations in Liechtenstein');
        self::assertStringStartsWith('5 Hochzeitslocations in Liechtenstein: ', $crawler->filter('.listing-hero__intro > p')->text());
        $title = $crawler->filter('title')->text();
        self::assertStringContainsString('in Liechtenstein', $title);
        self::assertStringNotContainsString('Kanton', $title);
        self::assertStringStartsWith('Hochzeitslocations in Liechtenstein: ', (string) $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertSame(['Hochzeitslocations', 'Liechtenstein'], $crawler->filter('nav.breadcrumb li.breadcrumb__item')->each(static fn (Crawler $item): string => $item->text()));
        self::assertSame(['Hochzeitslocations', 'Liechtenstein'], array_column($this->breadcrumbJsonLd($crawler), 'name'));
    }

    public function test_five_venues_in_one_town_switch_a_town_page_on(): void
    {
        $this->inTown('Stäfa', 4);
        $this->client->request('GET', '/eventlokale/zuerich/staefa');
        self::assertResponseStatusCodeSame(404);

        $staefa = $this->names($this->inTown('Stäfa', 1));
        $this->inTown('Meilen', 1);

        $crawler = $this->get('/eventlokale/zuerich/staefa');

        self::assertSelectorTextSame('.listing-hero__title', 'Hochzeitslocations in Stäfa');
        self::assertStringStartsWith('5 Hochzeitslocations in Stäfa:', $crawler->filter('.listing-hero__intro > p')->text());
        self::assertCount(5, $crawler->filter('article.card'));
        self::assertContains($staefa[0], $this->cardNames($crawler));

        // Stäfa's five venues make Zürich a canton page too, so the breadcrumb has its step.
        $items = $crawler->filter('nav.breadcrumb li.breadcrumb__item');
        self::assertSame(['Hochzeitslocations', 'Kanton Zürich', 'Stäfa'], $items->each(static fn (Crawler $item): string => $item->text()));
        self::assertSame('http://localhost/eventlokale/zuerich', $items->eq(1)->filter('a')->attr('href'));
        self::assertSame('page', $items->eq(2)->filter('[aria-current]')->attr('aria-current'));
        self::assertSame(['Hochzeitslocations', 'Kanton Zürich', 'Stäfa'], array_column($this->breadcrumbJsonLd($crawler), 'name'));

        $this->assertPublicAndCookieFree();
    }

    public function test_locations_is_the_current_page_on_a_canton_and_a_town_page(): void
    {
        $this->inTown('Stäfa', 5);

        foreach (['/eventlokale/zuerich', '/eventlokale/zuerich/staefa'] as $uri) {
            $crawler = $this->get($uri);
            self::assertSame('/eventlokale', $crawler->filter('.site-header__link[aria-current="page"]')->attr('href'), $uri);
        }
    }

    public function test_a_town_under_another_cantons_slug_is_404(): void
    {
        $this->inTown('Stäfa', 5);
        $this->inCanton(Canton::BE, 5);

        $this->client->request('GET', '/eventlokale/bern/staefa');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/eventlokale/staefa');
        self::assertResponseStatusCodeSame(404, 'A town has no single-segment path.');
    }

    public function test_five_castles_switch_the_building_page_on(): void
    {
        $this->withBuilding($this->inCanton(Canton::ZH, 4), 'castle');
        $this->client->request('GET', '/eventlokale/schloss');
        self::assertResponseStatusCodeSame(404);

        $this->withBuilding($this->inCanton(Canton::BE, 1), 'castle');
        $this->inCanton(Canton::BE, 1);

        $crawler = $this->get('/eventlokale/schloss');

        self::assertSelectorTextSame('.listing-hero__title', 'Hochzeit im Schloss');
        self::assertSame(
            '5 Locations für deine Hochzeit im Schloss: Vergleiche Fotos und Platz für Gäste und frag direkt an.',
            $crawler->filter('.listing-hero__intro > p')->text(),
        );
        self::assertCount(5, $crawler->filter('article.card'));
        self::assertSame(['Hochzeitslocations', 'Hochzeit im Schloss'], $crawler->filter('nav.breadcrumb li.breadcrumb__item')->each(static fn (Crawler $item): string => $item->text()));
        $this->assertPublicAndCookieFree();
    }

    public function test_the_path_wins_over_the_same_parameter_in_the_query(): void
    {
        $zurich = $this->names($this->inCanton(Canton::ZH, 5));
        $this->inCanton(Canton::BE, 5);

        $crawler = $this->get('/eventlokale/zuerich?canton=be');

        self::assertSelectorTextSame('.listing-hero__title', 'Hochzeitslocations im Kanton Zürich');
        self::assertSame($zurich, $this->cardNames($crawler));
    }

    public function test_query_filters_narrow_a_canton_page_and_pagination_keeps_its_path(): void
    {
        // 49 hold 100 guests (the factory's 120), so the filtered page still has a second page (#51).
        $this->inCanton(Canton::ZH, 49);
        $big = $this->zurichVenue(400);
        $this->inCanton(Canton::BE, 5);

        $crawler = $this->get('/eventlokale/zuerich?guests=300');
        self::assertSame([$big->name()], $this->cardNames($crawler));
        self::assertSelectorTextSame('.listing-hero__title', 'Hochzeitslocations im Kanton Zürich');

        $crawler = $this->get('/eventlokale/zuerich?guests=100');
        self::assertCount(48, $crawler->filter('article.card'));
        $pages = $crawler->filter('.pagination a.pagination__page')->each(static fn (Crawler $a): string => (string) $a->attr('href'));
        self::assertSame(['/eventlokale/zuerich?guests=100', '/eventlokale/zuerich?guests=100&page=2'], $pages);

        $crawler = $this->get('/eventlokale/zuerich?page=2');
        self::assertCount(2, $crawler->filter('article.card'));
        self::assertSame('/eventlokale/zuerich', $crawler->filter('.pagination a.pagination__arrow')->first()->attr('href'));
    }

    private function get(string $uri): Crawler
    {
        $crawler = $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful($uri);

        return $crawler;
    }

    /**
     * A page until the next Zurich midnight (exact values: PublicCacheHeadersTest), a 404 for an hour.
     */
    private function assertPublicAndCookieFree(): void
    {
        $response = $this->client->getResponse();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        if (404 === $response->getStatusCode()) {
            self::assertStringContainsString('s-maxage=3600', $cacheControl);
        } else {
            self::assertMatchesRegularExpression('/\bs-maxage=\d+\b/', $cacheControl);
            self::assertStringContainsString('stale-while-revalidate=86400', $cacheControl);
            self::assertStringContainsString('stale-if-error=86400', $cacheControl);
        }
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertSame([], $this->client->getCookieJar()->all());
        self::assertFalse($this->client->getRequest()->hasPreviousSession());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function breadcrumbJsonLd(Crawler $crawler): array
    {
        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            $data = json_decode((string) $script->textContent, true, flags: \JSON_THROW_ON_ERROR);
            if (\is_array($data) && 'BreadcrumbList' === ($data['@type'] ?? null)) {
                self::assertIsList($data['itemListElement']);

                return $data['itemListElement'];
            }
        }
        self::fail('No BreadcrumbList JSON-LD.');
    }

    /**
     * @return list<string> sorted
     */
    private function cardNames(Crawler $crawler): array
    {
        $names = $crawler->filter('article.card .card__link')->each(static fn (Crawler $link): string => $link->text());
        sort($names);

        return $names;
    }

    /**
     * @param list<Venue> $venues
     *
     * @return list<string> sorted
     */
    private function names(array $venues): array
    {
        $names = array_map(static fn (Venue $venue): string => $venue->name(), $venues);
        sort($names);

        return $names;
    }

    private function zurichVenue(int $capacityMax): Venue
    {
        $venue = VenueFactory::new()->withoutLocation(repository(CantonRow::class)->find('zh'))->create(['capacityMax' => $capacityMax]);
        VenueTranslationFactory::createOne(['venue' => $venue]);

        return $venue;
    }

    /**
     * The container's LandingPages, cleared, so it reads the data as the next request will.
     */
    private function landingPages(): LandingPages
    {
        $pages = self::getContainer()->get(LandingPages::class);
        self::assertInstanceOf(LandingPages::class, $pages);
        $pages->reset();

        return $pages;
    }
}
