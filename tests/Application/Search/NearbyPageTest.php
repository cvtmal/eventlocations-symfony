<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\Entity\Town;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * The near-me page (#52) in its four states, keyless (production until the owner sets the key) and keyed. The key is
 * set before the client boots, because the controller reads it when the container builds it (like VenueMapTest).
 */
final class NearbyPageTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const string URL = '/eventlokal-in-meiner-naehe';
    private const string PAGE_CACHE = 'public, s-maxage=86400, stale-if-error=86400, stale-while-revalidate=86400';
    private const array KEYS = ['GOOGLE_MAPS_JS_KEY', 'GOOGLE_MAPS_MAP_ID'];
    private const string RAW_KEY = 'test-key-"<&';

    private ?KernelBrowser $client = null;
    private EntityManagerInterface $em;
    /** @var array<string, array{mixed, mixed}> */
    private array $saved = [];

    protected function tearDown(): void
    {
        if (null !== $this->client) {
            $this->em->clear();
            $this->em->getConnection()->rollBack();
            $this->client = null;
        }
        parent::tearDown();

        foreach ($this->saved as $name => [$server, $env]) {
            $_SERVER[$name] = $server;
            $_ENV[$name] = $env;
        }
        $this->saved = [];
    }

    public function test_the_bare_keyless_page_has_the_controls_and_no_map_and_no_list(): void
    {
        $this->start('');
        $this->fixtures();

        $crawler = $this->page(self::URL);
        $response = $this->client()->getResponse();

        self::assertSame(self::PAGE_CACHE, $response->headers->get('Cache-Control'));
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertCount(1, $crawler->filter('form[role="search"] input[name="ort"]'));
        self::assertCount(1, $crawler->filter('form[data-near-me-geo][hidden] input[name="lat"]'));
        self::assertCount(0, $crawler->filter('[data-near-me-map]'));
        self::assertCount(0, $crawler->filter('.listing__results'));
        self::assertCount(0, $crawler->filter('#nearby-results'));
    }

    public function test_a_zip_lists_the_venues_within_30_km_nearest_first_with_their_distance(): void
    {
        $this->start('');
        $venues = $this->fixtures();

        $crawler = $this->page(self::URL.'?ort=8712');

        self::assertSame('2 Locations im Umkreis von 30 km von 8712 Stäfa', $crawler->filter('h2#nearby-results-heading')->text());
        self::assertSame(
            [$venues['stafa']->number(), $venues['maennedorf']->number()],
            $crawler->filter('.listing__results .card')->each(static fn (Crawler $card): int => (int) $card->attr('data-stats-venue')),
        );
        self::assertSame(['Entfernung 1 km', 'Entfernung 3 km'], $crawler->filter('.card-facts__item:nth-child(3)')->each(static fn (Crawler $li): string => $li->text()));
        self::assertCount(2, $crawler->filter('.listing__results h3.card__title'));
        foreach ($crawler->filter('.listing__results .card') as $card) {
            self::assertLessThanOrEqual(1, (new Crawler($card))->filter('.carousel__slide')->count());
        }
        self::assertCount(0, $crawler->filter('[data-near-me-map]'));
    }

    public function test_a_position_is_shown_as_the_rounded_point(): void
    {
        $this->start('');
        $this->fixtures();

        $crawler = $this->page(self::URL.'?lat=47.239&lng=8.721');

        self::assertSame('2 Locations im Umkreis von 30 km um deinen Standort', $crawler->filter('h2#nearby-results-heading')->text());
    }

    #[DataProvider('notices')]
    public function test_a_bad_position_or_an_unknown_place_shows_a_notice_and_no_list(string $query, string $selector, string $text): void
    {
        $this->start('');
        $this->fixtures();

        $crawler = $this->page(self::URL.'?'.$query);

        self::assertStringContainsString($text, $crawler->filter($selector)->text());
        self::assertCount(0, $crawler->filter('.listing__results'));
        self::assertCount(0, $crawler->filter('#nearby-results'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function notices(): iterable
    {
        yield 'outside the box' => ['lat=52.52&lng=13.40', '.nearby__notice', 'Für diesen Standort können wir keine Locations anzeigen.'];
        yield 'unknown place' => ['ort=xyzxyz', '.text-input__error', 'Diesen Ort haben wir nicht gefunden.'];
    }

    public function test_an_array_parameter_is_the_bare_state(): void
    {
        $this->start('');

        $crawler = $this->page(self::URL.'?ort[]=8712');

        self::assertCount(0, $crawler->filter('#nearby-results'));
        self::assertCount(0, $crawler->filter('.text-input__error'));
        self::assertCount(0, $crawler->filter('.nearby__notice'));
    }

    public function test_nothing_near_a_zip_links_to_the_listing_at_50_km_around_it(): void
    {
        $this->start('');
        $this->fixtures();

        $crawler = $this->page(self::URL.'?ort=3920');

        self::assertSame('Keine Location im Umkreis von 30 km.', $crawler->filter('.listing-empty__title')->text());
        $link = $crawler->filter('.listing-empty a');
        self::assertSame('/eventlokale?place=3920&radius=50', $link->attr('href'));
        self::assertSame('Locations im Umkreis von 50 km ansehen', $link->text());
    }

    public function test_nothing_near_a_position_links_to_the_listing_around_the_nearest_town(): void
    {
        $this->start('');
        $this->fixtures();

        $crawler = $this->page(self::URL.'?lat=46.02&lng=7.75');

        self::assertSame('/eventlokale?place='.$this->town('Zermatt')->id().'&radius=50', $crawler->filter('.listing-empty a')->attr('href'));
    }

    public function test_nothing_near_a_position_far_from_any_town_links_to_the_plain_listing(): void
    {
        $this->start('');
        $this->fixtures();

        $crawler = $this->page(self::URL.'?lat=45.60&lng=7.50');

        self::assertSame('/eventlokale', $crawler->filter('.listing-empty a')->attr('href'));
    }

    #[DataProvider('keylessStates')]
    public function test_no_keyless_state_names_google_or_the_map(string $query): void
    {
        $this->start('');
        $this->fixtures();

        $this->page(self::URL.$query);
        $html = (string) $this->client()->getResponse()->getContent();

        foreach (['googleapis', 'maps.google', 'data-maps-key', 'data-near-me-pins', 'data-near-me-map'] as $needle) {
            self::assertStringNotContainsString($needle, $html, $needle.' in '.$query);
        }
        self::assertMatchesRegularExpression('~<script type="module" src="/assets/js/near-me-[^"]+\.js"></script>~', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keylessStates(): iterable
    {
        yield 'bare' => [''];
        yield 'zip' => ['?ort=8712'];
        yield 'town' => ['?ort=Stäfa'];
        yield 'position' => ['?lat=47.23&lng=8.67'];
        yield 'empty' => ['?ort=3920'];
        yield 'not found' => ['?ort=xyzxyz'];
        yield 'bad position' => ['?lat=52.52&lng=13.40'];
    }

    public function test_the_bare_page_is_indexable_and_every_parameter_state_is_not(): void
    {
        $this->start('');

        $bare = $this->page(self::URL);
        self::assertCount(0, $bare->filter('meta[name="robots"]'));
        self::assertSame('http://localhost'.self::URL, $bare->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame('Hochzeitslocations in deiner Nähe | deine-eventlocation.ch', $bare->filter('title')->text());

        foreach (['?ort=8712', '?ort=', '?lat=47.23&lng=8.67', '?lat=52.52&lng=13.40', '?ort=xyzxyz'] as $query) {
            $crawler = $this->page(self::URL.$query);
            self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'), $query);
            self::assertSame('http://localhost'.self::URL, $crawler->filter('link[rel="canonical"]')->attr('href'), $query);
        }
    }

    public function test_with_a_key_the_bare_page_carries_every_published_pin_and_no_google_script(): void
    {
        $this->start(self::RAW_KEY);
        $this->fixtures();

        $crawler = $this->page(self::URL);
        $html = (string) $this->client()->getResponse()->getContent();
        $map = $crawler->filter('[data-near-me-map][role="region"]');

        self::assertCount(1, $map);
        // Visible from the first paint, so starting the map shifts nothing (CLS); without JS it holds the fallback line.
        self::assertNull($map->attr('hidden'));
        self::assertSame('', $map->filter('[data-near-me-canvas]')->attr('hidden'));
        self::assertNull($map->filter('[data-near-me-error]')->attr('hidden'));
        self::assertSame(self::RAW_KEY, $map->attr('data-maps-key'));
        self::assertStringContainsString('data-maps-key="test-key-&quot;&lt;&amp;"', $html);
        self::assertSame('DEMO_MAP_ID', $map->attr('data-map-id'));
        self::assertNull($map->attr('data-centre-lat'));
        self::assertSame('Karte: 3 Locations in der Schweiz', $map->attr('aria-label'));
        self::assertCount(0, $crawler->filter('[data-near-me-skip]'));

        $names = [];
        foreach ($this->pins($map) as $pin) {
            foreach ($pin['v'] as $venue) {
                self::assertArrayNotHasKey('k', $venue);
                $names[] = $venue['n'];
            }
        }
        sort($names);
        self::assertSame(['Genf am See', 'Männedorf am See', 'Stäfa am See'], $names);
        $this->assertNoGoogleScript($crawler, $html);
    }

    public function test_with_a_key_a_located_page_carries_the_centre_the_distances_and_the_skip_link(): void
    {
        $this->start(self::RAW_KEY);
        $this->fixtures();

        $crawler = $this->page(self::URL.'?lat=47.239&lng=8.721');
        $html = (string) $this->client()->getResponse()->getContent();
        $map = $crawler->filter('[data-near-me-map]');

        self::assertSame('47.24', $map->attr('data-centre-lat'));
        self::assertSame('8.72', $map->attr('data-centre-lng'));
        self::assertSame('30000', $map->attr('data-radius'));
        self::assertSame('Karte: 2 Locations im Umkreis von 30 km', $map->attr('aria-label'));
        self::assertSame('__count__ Locations', $map->attr('data-label-group'));
        $pins = $this->pins($map);
        self::assertSame('Stäfa am See', $pins[0]['v'][0]['n']);
        self::assertSame('1 km', $pins[0]['v'][0]['k']);
        self::assertSame('Stäfa', $pins[0]['v'][0]['p']);
        self::assertStringStartsWith('/eventlokal/', $pins[0]['v'][0]['u']);
        self::assertSame('#nearby-results', $crawler->filter('a[data-near-me-skip]:not([hidden])')->attr('href'));
        $this->assertNoGoogleScript($crawler, $html);
    }

    private function assertNoGoogleScript(Crawler $crawler, string $html): void
    {
        self::assertStringNotContainsString('<script src="https://maps', $html);
        foreach ($crawler->filter('script') as $script) {
            \assert($script instanceof \DOMElement);
            $src = $script->getAttribute('src');
            self::assertStringNotContainsString('maps.googleapis.com', $src);
            if ('' === $src) {
                self::assertSame('application/ld+json', $script->getAttribute('type'), 'Only JSON-LD is inline.');
            }
        }
    }

    /**
     * @return list<array{a: float, o: float, v: list<array<string, string>>}>
     */
    private function pins(Crawler $map): array
    {
        /** @var list<array{a: float, o: float, v: list<array<string, string>>}> $pins */
        $pins = json_decode((string) $map->attr('data-near-me-pins'), true, 512, \JSON_THROW_ON_ERROR);

        return $pins;
    }

    /**
     * Two published venues near Stäfa (one with three photos), one in Geneva, an unpublished one near Stäfa and one
     * without coordinates.
     *
     * @return array<string, Venue>
     */
    private function fixtures(): array
    {
        $stafa = $this->town('Stäfa');
        $maennedorf = $this->town('Männedorf');
        $geneva = $this->town('Genève');

        $venues = [
            'stafa' => $this->venue('Stäfa am See', $stafa, $stafa->lat(), $stafa->lng()),
            'maennedorf' => $this->venue('Männedorf am See', $maennedorf, $maennedorf->lat(), $maennedorf->lng()),
            'geneva' => $this->venue('Genf am See', $geneva, $geneva->lat(), $geneva->lng()),
            'draft' => $this->venue('Entwurf am See', $stafa, $stafa->lat(), $stafa->lng(), ['status' => VenueStatus::Unpublished]),
        ];
        foreach ([1, 2, 3] as $position) {
            VenuePhotoFactory::createOne(['venue' => $venues['stafa'], 'position' => $position]);
        }
        $unplaced = VenueFactory::new()->withoutLocation()->create(['name' => 'Ohne Koordinaten']);
        VenueTranslationFactory::createOne(['venue' => $unplaced]);
        $this->em->flush();

        return $venues;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function venue(string $name, Town $town, float $lat, float $lng, array $attributes = []): Venue
    {
        $venue = VenueFactory::createOne(['name' => $name, 'town' => $town, 'lat' => $lat, 'lng' => $lng] + $attributes);
        VenueTranslationFactory::createOne(['venue' => $venue]);

        return $venue;
    }

    private function town(string $name): Town
    {
        $town = repository(Town::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Town::class, $town);

        return $town;
    }

    private function start(string $key): void
    {
        foreach (self::KEYS as $name) {
            $this->saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null];
        }
        $_SERVER['GOOGLE_MAPS_JS_KEY'] = $_ENV['GOOGLE_MAPS_JS_KEY'] = $key;
        $_SERVER['GOOGLE_MAPS_MAP_ID'] = $_ENV['GOOGLE_MAPS_MAP_ID'] = 'DEMO_MAP_ID';

        $this->client = self::createClient();
        $this->client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->em->getConnection()->beginTransaction();
    }

    private function page(string $url): Crawler
    {
        $crawler = $this->client()->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        return $crawler;
    }

    private function client(): KernelBrowser
    {
        \assert(null !== $this->client);

        return $this->client;
    }
}
