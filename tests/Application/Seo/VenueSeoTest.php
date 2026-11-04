<?php

declare(strict_types=1);

namespace App\Tests\Application\Seo;

use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * SEO-1 and SEO-2 on the detail page.
 */
final class VenueSeoTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const string FULL_URL = 'http://localhost/hochzeitslocation/'.DetailVenues::FULL_SLUG;

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function urls(): iterable
    {
        yield 'clean' => [''];
        yield 'the inquiry confirmation' => ['?anfrage=gesendet&_hash=abc'];
        yield 'campaign parameters' => ['?utm_source=x'];
    }

    #[DataProvider('urls')]
    public function test_the_canonical_is_the_clean_url(string $query): void
    {
        DetailVenues::full($this->em);

        $crawler = $this->get('/hochzeitslocation/'.DetailVenues::FULL_SLUG.$query);

        self::assertSame(self::FULL_URL, $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame(self::FULL_URL, $crawler->filter('meta[property="og:url"]')->attr('content'));
        self::assertCount(0, $crawler->filter('meta[name="robots"]'));
    }

    public function test_social_tags_of_a_full_venue(): void
    {
        DetailVenues::full($this->em);

        $crawler = $this->get('/hochzeitslocation/'.DetailVenues::FULL_SLUG);

        self::assertSame('place', $crawler->filter('meta[property="og:type"]')->attr('content'));
        self::assertNotEmpty($crawler->filter('meta[property="place:location:latitude"]')->attr('content'));
        self::assertNotEmpty($crawler->filter('meta[property="place:location:longitude"]')->attr('content'));
        self::assertMatchesRegularExpression('#^http://localhost/media/\d+/[0-9a-f-]+-1280\.jpg$#', (string) $crawler->filter('meta[property="og:image"]')->attr('content'));
        self::assertSame('summary_large_image', $crawler->filter('meta[name="twitter:card"]')->attr('content'));
        self::assertSame('Schloss Seeblick: Hochzeit am See.', $crawler->filter('meta[property="og:description"]')->attr('content'));
    }

    public function test_json_ld_of_a_full_venue(): void
    {
        DetailVenues::full($this->em);

        $crawler = $this->get('/hochzeitslocation/'.DetailVenues::FULL_SLUG);

        $venue = $this->venueNode($crawler);
        self::assertSame(['EventVenue', 'LocalBusiness'], $venue['@type']);
        self::assertSame(self::FULL_URL.'#venue', $venue['@id']);
        self::assertSame(self::FULL_URL, $venue['url']);
        self::assertSame('Schloss Seeblick', $venue['name']);
        self::assertSame(300, $venue['maximumAttendeeCapacity']);
        self::assertSame(['https://seeblick.example', 'https://www.instagram.com/schloss.seeblick'], $venue['sameAs']);

        self::assertIsArray($venue['address']);
        self::assertSame('Seestrasse 1', $venue['address']['streetAddress']);
        self::assertSame('Zürich', $venue['address']['addressLocality']);
        self::assertSame('CH', $venue['address']['addressCountry']);
        self::assertNotEmpty($venue['address']['postalCode']);

        self::assertIsArray($venue['geo']);
        self::assertSame('GeoCoordinates', $venue['geo']['@type']);

        self::assertIsArray($venue['image']);
        self::assertCount(3, $venue['image']);
        self::assertStringStartsWith('http://localhost/media/', $venue['image'][0]);

        self::assertIsArray($venue['aggregateRating']);
        self::assertSame(['@type' => 'AggregateRating', 'ratingValue' => 9.2, 'ratingCount' => 474, 'bestRating' => 10], $venue['aggregateRating']);
    }

    public function test_the_breadcrumb_runs_from_the_listing_to_the_venue(): void
    {
        DetailVenues::full($this->em);

        $crawler = $this->get('/hochzeitslocation/'.DetailVenues::FULL_SLUG);

        $lists = $this->nodes($crawler, 'BreadcrumbList');
        self::assertCount(1, $lists);
        self::assertSame([
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Hochzeitslocations', 'item' => 'http://localhost/hochzeitslocations'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Schloss Seeblick'],
        ], $lists[0]['itemListElement']);
    }

    public function test_neither_phone_nor_email_reach_the_json_ld(): void
    {
        DetailVenues::full($this->em);

        $crawler = $this->get('/hochzeitslocation/'.DetailVenues::FULL_SLUG);

        $venue = $this->venueNode($crawler);
        self::assertArrayNotHasKey('telephone', $venue);
        self::assertArrayNotHasKey('email', $venue);
        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            self::assertStringNotContainsString(substr(DetailVenues::FULL_PHONE, 3), (string) $script->textContent);
            self::assertStringNotContainsString('info@seeblick.example', (string) $script->textContent);
        }
    }

    public function test_a_minimal_venue_without_photos(): void
    {
        DetailVenues::minimal();

        $crawler = $this->get('/hochzeitslocation/'.DetailVenues::MINIMAL_SLUG);

        self::assertCount(0, $crawler->filter('meta[property="og:image"]'));
        self::assertSame('summary', $crawler->filter('meta[name="twitter:card"]')->attr('content'));
        self::assertSame('Ein kleines Gartenhaus.', $crawler->filter('meta[name="description"]')->attr('content'));
        $venue = $this->venueNode($crawler);
        self::assertArrayNotHasKey('image', $venue);
        self::assertArrayNotHasKey('aggregateRating', $venue);
        self::assertIsArray($venue['address']);
        self::assertArrayNotHasKey('streetAddress', $venue['address']);
    }

    public function test_a_venue_with_only_a_canton(): void
    {
        DetailVenues::inCantonOnly();

        $crawler = $this->get('/hochzeitslocation/scheune-nur-kanton');

        self::assertSame('Scheune Nur Kanton im Kanton Zürich | Hochzeitslocation', $crawler->filter('title')->text());
        $venue = $this->venueNode($crawler);
        self::assertSame('EventVenue', $venue['@type']);
        self::assertSame(['@type' => 'AdministrativeArea', 'name' => 'Kanton Zürich'], $venue['containedInPlace']);
        self::assertArrayNotHasKey('address', $venue);
        self::assertArrayNotHasKey('geo', $venue);
        self::assertArrayNotHasKey('aggregateRating', $venue);
    }

    public function test_town_centre_coordinates_are_no_place_and_no_geo(): void
    {
        $venue = DetailVenues::full($this->em);
        $venue->relocate($venue->town(), $venue->zip(), $venue->street(), $venue->lat(), $venue->lng(), coordinatesFromTown: true);
        $this->em->flush();

        $crawler = $this->get('/hochzeitslocation/'.DetailVenues::FULL_SLUG);

        self::assertSame('website', $crawler->filter('meta[property="og:type"]')->attr('content'));
        self::assertCount(0, $crawler->filter('meta[property="place:location:latitude"]'));
        $node = $this->venueNode($crawler);
        self::assertArrayNotHasKey('geo', $node);
        self::assertIsArray($node['address']);
    }

    /**
     * @return array<string, mixed>
     */
    private function venueNode(Crawler $crawler): array
    {
        $nodes = array_values(array_filter(
            $this->jsonLd($crawler),
            static fn (array $block): bool => str_ends_with((string) ($block['@id'] ?? ''), '#venue'),
        ));
        self::assertCount(1, $nodes);

        return $nodes[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nodes(Crawler $crawler, string $type): array
    {
        return array_values(array_filter($this->jsonLd($crawler), static fn (array $block): bool => $type === ($block['@type'] ?? null)));
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

    private function get(string $uri): Crawler
    {
        $crawler = $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful($uri);

        return $crawler;
    }
}
