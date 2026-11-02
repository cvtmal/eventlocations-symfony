<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenuePhotoTranslationFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\ValueObject\Canton;
use App\Search\ReadModel\KnownTermsQuery;
use App\Search\ReadModel\ListingCard;
use App\Search\ReadModel\ListingQuery;
use App\Search\ReadModel\SearchCriteria;
use App\Shared\ReadModel\ContentBlocks;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Cms\ResetsContentBlocks;
use App\Tests\Support\Search\LandingFixtures;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The home page `/` (#36). Every test builds its own venues, and the client shares the test's
 * connection (no reboot), so they are visible and rolled back afterwards.
 */
final class HomePageTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use ImportsTownDataset;
    use LandingFixtures;
    use ResetsContentBlocks;

    /** Two hours to the next Zurich midnight. */
    private const FROZEN_NOW = '2026-10-30 22:00:00 Europe/Zurich';
    private const CACHE_CONTROL = 'public, s-maxage=7200, stale-if-error=86400, stale-while-revalidate=86400';

    private const STAFF_TEXT = "**Ein Text** aus dem CMS.\n\n<script>alert(1)</script>";

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        self::mockTime(self::FROZEN_NOW);
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

    public function test_the_page_has_seven_sections_in_board_order_and_one_h1_inside_main(): void
    {
        $this->inCanton(Canton::ZH, 5);

        $crawler = $this->get('/');

        self::assertSame(
            [
                'Hochzeit feiern in den schönsten Locations der Schweiz',
                'Kategorien durchsuchen',
                'In vier Schritten zur Traumlocation',
                'Locations im Spotlight',
                'Planen Sie Ihre Hochzeit',
                'Die passende Hochzeitslocation für Ihren Tag',
                'Hochzeitslocations nach Region',
            ],
            $crawler->filter('main section')->each(static fn (Crawler $section): string => $section->filter('h1, h2')->first()->text()),
        );
        self::assertCount(1, $crawler->filter('h1'));
        self::assertCount(1, $crawler->filter('main h1'));
    }

    public function test_the_head_is_the_home_pages_own(): void
    {
        $crawler = $this->get('/');

        self::assertSame('http://localhost/', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame('website', $crawler->filter('meta[property="og:type"]')->attr('content'));
        self::assertCount(0, $crawler->filter('meta[name="robots"]'));
        self::assertCount(1, $crawler->filter('link[rel="alternate"][hreflang="de-CH"]'));
        self::assertCount(1, $crawler->filter('link[rel="alternate"][hreflang="x-default"]'));
        self::assertStringContainsString('"WebSite"', $crawler->filter('script[type="application/ld+json"]')->text());
        self::assertSame('Hochzeitslocations in der Schweiz finden | Hochzeitslocation', $crawler->filter('title')->text());
        self::assertSame(
            'Finden Sie die passende Hochzeitslocation in der Schweiz: nach Region, Location-Art und Gästezahl suchen und direkt bei der Location anfragen.',
            $crawler->filter('meta[name="description"]')->attr('content'),
        );
    }

    /** Demand 3: the page adds no script, so carousel.js stays off it. */
    public function test_the_page_loads_only_the_two_scripts_of_the_layout(): void
    {
        $this->inCanton(Canton::ZH, 3);

        $crawler = $this->get('/');

        $scripts = $crawler->filter('script[src]')->each(static fn (Crawler $script): string => (string) $script->attr('src'));
        self::assertCount(2, $scripts);
        self::assertCount(1, array_filter($scripts, static fn (string $src): bool => str_contains($src, 'overlay')));
        self::assertCount(1, array_filter($scripts, static fn (string $src): bool => str_contains($src, 'merkliste')));
        self::assertCount(0, array_filter($scripts, static fn (string $src): bool => str_contains($src, 'carousel')));
    }

    public function test_the_page_is_shared_cached_to_the_next_midnight_with_no_session_or_cookie(): void
    {
        $this->inCanton(Canton::ZH, 3);

        $this->get('/');

        $response = $this->client->getResponse();
        self::assertSame(self::CACHE_CONTROL, $response->headers->get('Cache-Control'));
        self::assertSame([], $response->headers->getCookies());
        self::assertNotContains('Cookie', $response->getVary());
    }

    public function test_the_form_submits_to_the_listing_and_all_three_fields_reach_the_criteria(): void
    {
        $this->withBuilding($this->inCanton(Canton::ZH, 5), 'castle');

        $crawler = $this->get('/');
        $form = $crawler->filter('form.home-search');
        self::assertSame('/hochzeitslocations', $form->attr('action'));
        self::assertSame('get', $form->attr('method'));

        $this->client->submit($form->form(), ['canton' => 'zh', 'building[0]' => 'castle', 'guests' => '80']);

        self::assertResponseIsSuccessful();
        self::assertSame('/hochzeitslocations', $this->client->getRequest()->getPathInfo());
        $terms = self::getContainer()->get(KnownTermsQuery::class);
        self::assertInstanceOf(KnownTermsQuery::class, $terms);
        $criteria = SearchCriteria::fromQuery($this->client->getRequest()->query, $terms->all());
        self::assertSame(Canton::ZH, $criteria->canton);
        self::assertSame(80, $criteria->guests);
        self::assertSame(['building' => ['castle']], $criteria->terms);
    }

    public function test_the_form_submitted_empty_is_the_unfiltered_listing(): void
    {
        $this->inCanton(Canton::ZH, 5);

        $crawler = $this->client->submit($this->get('/')->filter('form.home-search')->form());

        self::assertResponseIsSuccessful();
        $terms = self::getContainer()->get(KnownTermsQuery::class);
        self::assertInstanceOf(KnownTermsQuery::class, $terms);
        self::assertEquals(SearchCriteria::none(), SearchCriteria::fromQuery($this->client->getRequest()->query, $terms->all()));
        self::assertCount(0, $crawler->filter('meta[name="robots"]'));
    }

    public function test_the_selects_hold_the_live_cantons_by_name_and_the_five_kinds(): void
    {
        $this->inCanton(Canton::TI, 5);
        $this->inCanton(Canton::TG, 5);
        $this->inCanton(Canton::ZH, 5);
        $this->inCanton(Canton::BE, 4);

        $crawler = $this->get('/');

        // Tessin before Thurgau is the name's order; the codes run the other way.
        self::assertSame(
            ['Alle Kantone', 'Tessin', 'Thurgau', 'Zürich'],
            $crawler->filter('#home-canton option')->each(static fn (Crawler $option): string => $option->text()),
        );
        self::assertSame(
            ['', 'ti', 'tg', 'zh'],
            $crawler->filter('#home-canton option')->each(static fn (Crawler $option): string => (string) $option->attr('value')),
        );
        $kinds = $crawler->filter('#home-type option');
        self::assertCount(6, $kinds);
        self::assertSame('Alle Arten', $kinds->first()->text());
    }

    public function test_a_tile_links_to_the_landing_page_over_the_threshold_and_to_the_filter_under_it(): void
    {
        $this->withBuilding($this->inCanton(Canton::ZH, 5), 'castle');

        $links = $this->get('/')->filter('.home-category')->each(static fn (Crawler $tile): string => (string) $tile->attr('href'));

        self::assertCount(5, $links);
        self::assertContains('/hochzeitslocations/schloss', $links);
        self::assertContains('/hochzeitslocations?building%5B0%5D=hotel', $links);
    }

    public function test_spotlight_shows_the_head_of_the_listing_with_one_photo_each(): void
    {
        $venues = $this->inCanton(Canton::ZH, 4);
        $premium = VenueFactory::new()->create(['name' => 'Schloss Bezahlt', 'tier' => Tier::Premium, 'town' => $this->town('Stäfa')]);
        VenueTranslationFactory::createOne(['venue' => $premium]);
        foreach ([...$venues, $premium] as $venue) {
            $this->photos($venue, 2);
        }
        $this->flush();

        $crawler = $this->get('/');

        $query = self::getContainer()->get(ListingQuery::class);
        self::assertInstanceOf(ListingQuery::class, $query);
        $expected = array_map(static fn (ListingCard $card): string => $card->name, $query->first(3, 1, 'de'));
        $cards = $crawler->filter('.home-spotlight .card');
        self::assertCount(3, $cards);
        self::assertSame($expected, $cards->each(static fn (Crawler $card): string => $card->filter('.card__link')->text()));
        self::assertSame('Schloss Bezahlt', $expected[0]);
        self::assertCount(3, $crawler->filter('.home-spotlight .card img'), 'One img per card: the carousel controls stay hidden.');
        self::assertCount(3, $crawler->filter('.home-spotlight h3.card__title'));
        self::assertCount(3, $crawler->filter('.home-spotlight [data-merken]'));
        self::assertCount(0, $crawler->filter('.home-spotlight img[loading="eager"]'));
    }

    public function test_the_hero_uses_the_photo_at_the_configured_hash(): void
    {
        $hash = self::getContainer()->getParameter('home.hero_photo');
        self::assertIsString($hash);
        [$venue] = $this->inCanton(Canton::ZH, 1);
        $this->photos($venue, 1);
        $photo = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2, 'originalPath' => 'content/hero/'.$hash.'.jpg']);
        VenuePhotoTranslationFactory::createOne(['photo' => $photo, 'alt' => 'Der See im Abendlicht']);
        $this->flush();

        $crawler = $this->get('/');

        self::assertSame('Der See im Abendlicht', $crawler->filter('.home-hero__media img')->attr('alt'));
        $this->assertOneHeroImage($crawler);
    }

    public function test_the_hero_falls_back_on_the_first_card_with_a_photo(): void
    {
        [$first] = $this->inCanton(Canton::ZH, 1);
        $photo = VenuePhotoFactory::createOne(['venue' => $first, 'position' => 1]);
        VenuePhotoTranslationFactory::createOne(['photo' => $photo, 'alt' => 'Das Haus am Hang']);
        $this->flush();

        $crawler = $this->get('/');

        self::assertSame('Das Haus am Hang', $crawler->filter('.home-hero__media img')->attr('alt'));
        $this->assertOneHeroImage($crawler);
    }

    public function test_the_catalogue_text_stands_until_staff_store_one(): void
    {
        $crawler = $this->get('/');

        self::assertCount(2, $crawler->filter('.home-about__text p'));
        self::assertCount(7, $crawler->filter('.home-about__kinds dt'));
        self::assertCount(0, $crawler->filter('.home-about__staff'));

        $this->storeText();
        $crawler = $this->get('/');

        self::assertSame('Ein Text', $crawler->filter('.home-about__staff strong')->text());
        self::assertStringNotContainsString('Jede Hochzeit ist einzigartig', $crawler->filter('.home-about__text')->text());
        self::assertCount(7, $crawler->filter('.home-about__kinds dt'));
        self::assertCount(0, $crawler->filter('.home-about script'));
        self::assertStringNotContainsString('<script>alert', (string) $this->client->getResponse()->getContent());
    }

    public function test_the_regions_are_the_live_cantons_by_name_and_link_to_their_landing_pages(): void
    {
        $this->inCanton(Canton::TI, 5);
        $this->inCanton(Canton::TG, 5);
        $this->inCanton(Canton::ZH, 5);

        $crawler = $this->get('/');

        self::assertSame(
            [
                ['Tessin', '/hochzeitslocations/tessin'],
                ['Thurgau', '/hochzeitslocations/thurgau'],
                ['Zürich', '/hochzeitslocations/zuerich'],
            ],
            $crawler->filter('#regionen a')->each(static fn (Crawler $chip): array => [$chip->text(), (string) $chip->attr('href')]),
        );
    }

    public function test_with_no_published_venue_the_frame_form_and_text_stay(): void
    {
        $this->inCanton(Canton::ZH, 5, VenueStatus::Unpublished);

        $crawler = $this->get('/');

        self::assertCount(0, $crawler->filter('.home-spotlight'));
        self::assertCount(1, $crawler->filter('.home-hero__media'));
        self::assertCount(0, $crawler->filter('.home-hero__media img'));
        self::assertCount(0, $crawler->filter('[fetchpriority]'));
        self::assertCount(0, $crawler->filter('meta[property="og:image"]'));
        self::assertCount(1, $crawler->filter('form.home-search'));
        self::assertCount(2, $crawler->filter('.home-about__text p'));
    }

    public function test_with_no_live_canton_the_regions_go_and_the_select_keeps_its_first_option(): void
    {
        $this->inCanton(Canton::ZH, 4);

        $crawler = $this->get('/');

        self::assertCount(0, $crawler->filter('#regionen'));
        self::assertCount(1, $crawler->filter('#home-canton option'));
    }

    public function test_fewer_than_three_venues_fill_what_they_can(): void
    {
        $this->inCanton(Canton::ZH, 2);

        self::assertCount(2, $this->get('/')->filter('.home-spotlight .card'));
    }

    public function test_a_spotlight_venue_with_no_town_and_no_photo_still_renders(): void
    {
        DetailVenues::inCantonOnly();
        $this->flush();

        $crawler = $this->get('/');

        self::assertSame('Scheune Nur Kanton', $crawler->filter('.home-spotlight .card__link')->text());
        self::assertCount(1, $crawler->filter('.home-spotlight .card__placeholder'));
        self::assertCount(0, $crawler->filter('.home-hero__media img'));
    }

    public function test_a_query_string_keeps_the_page_and_its_canonical(): void
    {
        $crawler = $this->get('/?canton=zh');

        self::assertSame('http://localhost/', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertCount(0, $crawler->filter('meta[name="robots"]'));
    }

    public function test_the_page_runs_four_queries_warm_and_eight_cold(): void
    {
        foreach ($this->inCanton(Canton::ZH, 5) as $venue) {
            $this->photos($venue, 1);
        }
        $this->flush();
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        // #26: on an empty pool the first hit adds four statements: begin, try-lock, the SELECT, commit.
        $holder->reset();
        $this->get('/');
        self::assertCount(8, $holder->getData()['default'] ?? []);

        $blocks = self::getContainer()->get(ContentBlocks::class);
        self::assertInstanceOf(ContentBlocks::class, $blocks);
        $blocks->refresh();

        // Cards, their photos, the hero's hash and the landing pages' one statement.
        $holder->reset();
        $this->get('/');
        self::assertCount(4, $holder->getData()['default'] ?? []);
    }

    private function assertOneHeroImage(Crawler $crawler): void
    {
        self::assertCount(1, $crawler->filter('[fetchpriority="high"]'));
        self::assertSame('high', $crawler->filter('.home-hero__media img')->attr('fetchpriority'));
        self::assertNotEmpty($crawler->filter('.home-hero__media img')->attr('width'));
        self::assertNotEmpty($crawler->filter('.home-hero__media img')->attr('height'));
        self::assertNotEmpty($crawler->filter('meta[property="og:image"]')->attr('content'));
    }

    private function photos(Venue $venue, int $count): void
    {
        for ($position = 1; $position <= $count; ++$position) {
            VenuePhotoFactory::createOne(['venue' => $venue, 'position' => $position]);
        }
    }

    private function flush(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->flush();
    }

    private function storeText(): void
    {
        $this->connection->insert('content_blocks', ['key' => 'home.text', 'locale' => 'de', 'body_markdown' => self::STAFF_TEXT, 'updated_at' => '2026-10-31 08:00:00+00']);
        $blocks = self::getContainer()->get(ContentBlocks::class);
        self::assertInstanceOf(ContentBlocks::class, $blocks);
        $blocks->refresh();
    }

    private function get(string $uri): Crawler
    {
        $crawler = $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
