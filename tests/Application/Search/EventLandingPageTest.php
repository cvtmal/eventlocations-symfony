<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Factory\VenuePhotoFactory;
use App\Geo\ValueObject\Canton;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Search\LandingFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The event type landing pages (F8): `/eventlokale/{event}` and `/eventlokale/{canton}/{event}`,
 * switched on by data at `%landing.min_venues%` (5 in test), never for Hochzeit (D31). Their
 * h1, title, breadcrumb, JSON-LD, share card (D35), LP-5 links and sitemap entries.
 */
final class EventLandingPageTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use LandingFixtures;

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

    public function test_an_event_page_renders_its_heading_intro_title_and_breadcrumb(): void
    {
        $this->withEventType($this->inCanton(Canton::ZH, 3), 'seminar');
        $this->withEventType($this->inCanton(Canton::BE, 2), 'seminar');

        $crawler = $this->get('/eventlokale/seminar');

        self::assertSelectorTextSame('.listing-hero__title', 'Eventlokale für Seminare');
        self::assertSame(
            '5 Eventlokale für Seminare in der ganzen Schweiz: Hier findest du Seminarräume für Kurse und Weiterbildungen, vergleichst Fotos und Platz für Gäste und fragst direkt bei der Location an.',
            $crawler->filter('.listing-hero__intro > p')->text(),
        );
        self::assertCount(5, $crawler->filter('article.card'));
        self::assertSame('5 Eventlokale für Seminare | deine-eventlocation.ch', $crawler->filter('title')->text());
        self::assertSame('http://localhost/eventlokale/seminar', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame('index, follow', $this->robots($crawler));
        $items = $crawler->filter('nav.breadcrumb li.breadcrumb__item');
        self::assertCount(2, $items);
        self::assertSame('http://localhost/eventlokale', $items->eq(0)->filter('a')->attr('href'));
        self::assertSame('Seminare', $items->eq(1)->text());
    }

    public function test_an_event_page_in_a_canton_has_the_canton_in_its_heading_and_breadcrumb(): void
    {
        $this->withEventType($this->inCanton(Canton::ZH, 5), 'apero');

        $crawler = $this->get('/eventlokale/zuerich/apero');

        self::assertSelectorTextSame('.listing-hero__title', 'Eventlokale für Apéros im Kanton Zürich');
        self::assertSame(
            '5 Eventlokale für Apéros im Kanton Zürich: Hier vergleichst du Fotos und Platz für Gäste und fragst direkt bei der Location an.',
            $crawler->filter('.listing-hero__intro > p')->text(),
        );
        self::assertSame('Apéros im Kanton Zürich | deine-eventlocation.ch', $crawler->filter('title')->text());
        self::assertLessThanOrEqual(60, mb_strlen($crawler->filter('title')->text()));
        self::assertSame('http://localhost/eventlokale/zuerich/apero', $crawler->filter('link[rel="canonical"]')->attr('href'));
        $items = $crawler->filter('nav.breadcrumb li.breadcrumb__item');
        self::assertSame(['Kanton Zürich', 'Apéros'], \array_slice($items->each(static fn (Crawler $item): string => $item->text()), 1));
        self::assertSame('http://localhost/eventlokale/zuerich', $items->eq(1)->filter('a')->attr('href'));
    }

    public function test_hochzeit_has_no_landing_page_and_a_thin_event_type_none_either(): void
    {
        $this->withEventType($this->inCanton(Canton::ZH, 6), 'wedding');
        $this->withEventType($this->inCanton(Canton::BE, 4), 'party');

        foreach (['/eventlokale/hochzeit', '/eventlokale/zuerich/hochzeit', '/eventlokale/wedding', '/eventlokale/party', '/eventlokale/bern/party'] as $uri) {
            $this->client->request('GET', $uri);
            self::assertResponseStatusCodeSame(404, $uri);
        }
        $crawler = $this->get('/eventlokale/zuerich?event[]=wedding');
        self::assertSame('noindex, follow', $this->robots($crawler));
        self::assertSame('http://localhost/eventlokale/zuerich', $crawler->filter('link[rel="canonical"]')->attr('href'));
    }

    public function test_the_event_filter_canonicalises_to_its_event_page(): void
    {
        $this->withEventType($this->inCanton(Canton::ZH, 5), 'seminar');

        foreach (['/eventlokale?event[]=seminar' => 'http://localhost/eventlokale/seminar', '/eventlokale/zuerich?event[]=seminar' => 'http://localhost/eventlokale/zuerich/seminar'] as $uri => $canonical) {
            $crawler = $this->get($uri);
            self::assertSame('noindex, follow', $this->robots($crawler), $uri);
            self::assertSame($canonical, $crawler->filter('link[rel="canonical"]')->attr('href'), $uri);
        }
        // The event chip's remove link leaves the event page for the plain listing.
        $chips = $this->get('/eventlokale/seminar')->filter('a[href="/eventlokale"]');
        self::assertGreaterThan(0, $chips->count());
    }

    public function test_an_event_page_shares_the_default_card_and_its_json_ld_has_no_rating_or_count(): void
    {
        [$venue] = $this->withEventType($this->inCanton(Canton::ZH, 5), 'seminar');
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);

        $crawler = $this->get('/eventlokale/seminar');

        self::assertSame('http://localhost/brand/og-default.png', $crawler->filter('meta[property="og:image"]')->attr('content'));
        self::assertSame('http://localhost/brand/og-default.png', $crawler->filter('meta[name="twitter:image"]')->attr('content'));
        $types = [];
        foreach ($crawler->filter('script[type="application/ld+json"]')->each(static fn (Crawler $script): string => $script->text()) as $json) {
            self::assertDoesNotMatchRegularExpression('/rating|review|numberOfItems|telephone/i', $json);
            $data = json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            $types[] = $data['@type'] ?? null;
        }
        self::assertContains('ItemList', $types);
        self::assertContains('BreadcrumbList', $types);
    }

    public function test_lp5_links_the_event_pages_and_the_sitemap_lists_them(): void
    {
        $this->withEventType($this->inCanton(Canton::ZH, 5), 'seminar');
        $this->withEventType($this->inCanton(Canton::BE, 5), 'apero');

        $links = static fn (Crawler $crawler): array => $crawler->filter('.related__list a')->each(static fn (Crawler $link): string => $link->text().' '.$link->attr('href'));
        $listing = $links($this->get('/eventlokale'));
        self::assertContains('Eventlokale für Seminare /eventlokale/seminar', $listing);
        self::assertContains('Eventlokale für Apéros /eventlokale/apero', $listing);

        $canton = $links($this->get('/eventlokale/zuerich'));
        self::assertContains('Seminare im Kanton Zürich /eventlokale/zuerich/seminar', $canton);
        self::assertNotContains('Apéros im Kanton Bern /eventlokale/bern/apero', $canton, 'A canton page lists its own event pages first, not the others.');

        $event = $links($this->get('/eventlokale/seminar'));
        self::assertSame('Seminare im Kanton Zürich /eventlokale/zuerich/seminar', $event[0], 'An event page lists its cantons first.');
        self::assertNotContains('Eventlokale für Seminare /eventlokale/seminar', $event, 'Never the current page.');

        $eventCanton = $links($this->get('/eventlokale/zuerich/seminar'));
        self::assertContains('Eventlokale für Seminare /eventlokale/seminar', $eventCanton);

        $this->client->request('GET', '/sitemap-pages.xml');
        self::assertResponseIsSuccessful();
        $sitemap = (string) $this->client->getResponse()->getContent();
        foreach (['/eventlokale/seminar', '/eventlokale/apero', '/eventlokale/zuerich/seminar', '/eventlokale/bern/apero'] as $path) {
            self::assertStringContainsString('<loc>http://localhost'.$path.'</loc>', $sitemap);
        }
        self::assertStringNotContainsString('hochzeit', $sitemap);

        $this->client->request('GET', '/llms.txt');
        self::assertStringContainsString('(http://localhost/eventlokale/zuerich/seminar)', (string) $this->client->getResponse()->getContent());
    }

    private function get(string $uri): Crawler
    {
        $crawler = $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful($uri);

        return $crawler;
    }

    private function robots(Crawler $crawler): ?string
    {
        $robots = $crawler->filter('meta[name="robots"]');

        return $robots->count() > 0 ? $robots->attr('content') : 'index, follow';
    }
}
