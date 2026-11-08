<?php

declare(strict_types=1);

namespace App\Tests\Application\Seo;

use App\Factory\LastMinuteOfferFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\ValueObject\Canton;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Search\LandingFixtures;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * SEO-3. The schemas are local copies, so the test needs no network. Paging past 1,000 venues is
 * proven at query level (VenueSitemapQueryTest), as is `lastmod` per landing group.
 */
final class SitemapTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use LandingFixtures;

    private const string SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    private const string IMAGE_NS = 'http://www.google.com/schemas/sitemap-image/1.1';
    private const string SCHEMAS = __DIR__.'/../../Fixtures/Seo/';

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

    public function test_the_index_lists_the_pages_and_every_venue_file(): void
    {
        DetailVenues::minimal();

        $xml = $this->fetch('/sitemap.xml');

        self::assertSame(['http://localhost/sitemap-pages.xml', 'http://localhost/sitemap-venues-1.xml'], $this->values($xml, 'loc', self::SITEMAP_NS));
        self::assertCount(2, $xml->getElementsByTagNameNS(self::SITEMAP_NS, 'lastmod'));
        $this->assertValid($xml, 'siteindex.xsd');
    }

    public function test_without_venues_the_index_still_lists_the_pages(): void
    {
        $xml = $this->fetch('/sitemap.xml');

        self::assertSame(['http://localhost/sitemap-pages.xml'], $this->values($xml, 'loc', self::SITEMAP_NS));
        $this->assertValid($xml, 'siteindex.xsd');
    }

    public function test_every_published_venue_is_listed_and_no_unpublished_one(): void
    {
        DetailVenues::full($this->em);
        DetailVenues::minimal();
        VenueTranslationFactory::createOne([
            'venue' => VenueFactory::createOne(['status' => VenueStatus::Unpublished, 'town' => DetailVenues::town('Bern')]),
            'slug' => 'nicht-oeffentlich',
        ]);

        $xml = $this->fetch('/sitemap-venues-1.xml');

        $locs = $this->values($xml, 'loc', self::SITEMAP_NS);
        self::assertCount(2, $locs);
        self::assertContains('http://localhost/eventlokal/'.DetailVenues::FULL_SLUG, $locs);
        self::assertContains('http://localhost/eventlokal/'.DetailVenues::MINIMAL_SLUG, $locs);
        $images = $this->values($xml, 'loc', self::IMAGE_NS);
        self::assertCount(4, $images, 'three gallery photos and the first photo of the hall: what the page shows (F12)');
        self::assertStringStartsWith('http://localhost/media/', $images[0]);
        $this->assertValid($xml, 'sitemap-with-images.xsd');
    }

    public function test_the_pages_file_lists_the_listing_with_its_newest_change(): void
    {
        DetailVenues::minimal();

        $xml = $this->fetch('/sitemap-pages.xml');

        self::assertSame(['http://localhost/', 'http://localhost/eventlokale', 'http://localhost/eventlokal-in-meiner-naehe', 'http://localhost/impressum', 'http://localhost/datenschutz'], $this->values($xml, 'loc', self::SITEMAP_NS));
        self::assertCount(3, $xml->getElementsByTagNameNS(self::SITEMAP_NS, 'lastmod'));
        $this->assertValid($xml, 'sitemap-with-images.xsd');
    }

    /**
     * G14: «Kurzfristig frei» joins the pages file after the near-me page, without lastmod, only while it lists an offer.
     */
    public function test_the_pages_file_lists_kurzfristig_frei_only_while_it_lists_an_offer(): void
    {
        $venue = DetailVenues::minimal(tier: KnownTiers::PRO);
        $offer = LastMinuteOfferFactory::createOne(['venue' => $venue]);

        $xml = $this->fetch('/sitemap-pages.xml');

        self::assertSame(['http://localhost/', 'http://localhost/eventlokale', 'http://localhost/eventlokal-in-meiner-naehe', 'http://localhost/kurzfristig-frei', 'http://localhost/impressum', 'http://localhost/datenschutz'], $this->values($xml, 'loc', self::SITEMAP_NS));
        self::assertCount(3, $xml->getElementsByTagNameNS(self::SITEMAP_NS, 'lastmod'));

        $this->connection->executeStatement('DELETE FROM last_minute_offers WHERE id = ?', [$offer->id()]);
        self::assertNotContains('http://localhost/kurzfristig-frei', $this->values($this->fetch('/sitemap-pages.xml'), 'loc', self::SITEMAP_NS));
    }

    public function test_the_pages_file_lists_every_live_landing_page_once_with_its_newest_change(): void
    {
        $staefa = $this->inTown('Stäfa', 5);
        $this->withBuilding(\array_slice($staefa, 0, 4), 'hotel');
        $bern = $this->withBuilding($this->inCanton(Canton::BE, 5), 'castle');
        $luzern = $this->inCanton(Canton::LU, 4);
        VenuePhotoFactory::createOne(['venue' => $bern[4], 'position' => 1]);
        $this->em->flush();
        foreach (['venues', 'venue_translations', 'venue_photos'] as $table) {
            $this->connection->executeStatement(\sprintf('UPDATE %s SET updated_at = :at', $table), ['at' => '2026-01-01 10:00:00+00']);
        }
        $this->setUpdatedAt('venue_translations', 'venue_id', $staefa[2]->id(), '2026-03-01 10:00:00+00');
        $this->setUpdatedAt('venue_photos', 'venue_id', $bern[4]->id(), '2026-02-01 10:00:00+00');
        $this->setUpdatedAt('venues', 'id', $luzern[0]->id(), '2026-09-01 10:00:00+00');

        $xml = $this->fetch('/sitemap-pages.xml');

        $lastmods = [];
        foreach ($xml->getElementsByTagNameNS(self::SITEMAP_NS, 'url') as $url) {
            $loc = $url->getElementsByTagNameNS(self::SITEMAP_NS, 'loc')->item(0)?->textContent;
            self::assertIsString($loc);
            self::assertArrayNotHasKey($loc, $lastmods, $loc.' is listed twice');
            $lastmods[$loc] = $url->getElementsByTagNameNS(self::SITEMAP_NS, 'lastmod')->item(0)?->textContent;
        }
        self::assertSame('http://localhost/', array_key_first($lastmods));
        ksort($lastmods);
        // Luzern (4 venues) and the hotels (4) are under the threshold; Luzern still moves the listing.
        self::assertSame([
            'http://localhost/' => '2026-09-01T10:00:00+00:00',
            'http://localhost/datenschutz' => null,
            'http://localhost/eventlokal-in-meiner-naehe' => '2026-09-01T10:00:00+00:00',
            'http://localhost/eventlokale' => '2026-09-01T10:00:00+00:00',
            'http://localhost/eventlokale/bern' => '2026-02-01T10:00:00+00:00',
            'http://localhost/eventlokale/schloss' => '2026-02-01T10:00:00+00:00',
            'http://localhost/eventlokale/zuerich' => '2026-03-01T10:00:00+00:00',
            'http://localhost/eventlokale/zuerich/staefa' => '2026-03-01T10:00:00+00:00',
            'http://localhost/impressum' => null,
        ], $lastmods);
        $this->assertValid($xml, 'sitemap-with-images.xsd');

        foreach (array_keys($lastmods) as $loc) {
            $this->client->request('GET', $loc);
            self::assertResponseIsSuccessful($loc);
        }
    }

    public function test_four_liechtenstein_venues_give_no_landing_page_entry(): void
    {
        $this->inCanton(Canton::LI, 4);

        $locations = $this->values($this->fetch('/sitemap-pages.xml'), 'loc', self::SITEMAP_NS);

        self::assertNotContains('http://localhost/eventlokale/liechtenstein', $locations, 'Under the threshold (#46, owner answer C).');
        self::assertContains('http://localhost/eventlokale', $locations);
    }

    public function test_without_venues_the_listing_has_no_lastmod(): void
    {
        $xml = $this->fetch('/sitemap-pages.xml');

        self::assertCount(0, $xml->getElementsByTagNameNS(self::SITEMAP_NS, 'lastmod'));
        $this->assertValid($xml, 'sitemap-with-images.xsd');
    }

    public function test_it_is_cacheable_xml_without_priority_or_changefreq(): void
    {
        DetailVenues::minimal();

        foreach (['/sitemap.xml', '/sitemap-pages.xml', '/sitemap-venues-1.xml'] as $path) {
            $this->client->request('GET', $path);

            $response = $this->client->getResponse();
            self::assertSame('application/xml; charset=UTF-8', $response->headers->get('Content-Type'), $path);
            self::assertStringContainsString('s-maxage=86400', (string) $response->headers->get('Cache-Control'), $path);
            self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'), $path);
            self::assertStringStartsWith('<?xml', (string) $response->getContent(), $path);
            self::assertStringNotContainsString('priority', (string) $response->getContent(), $path);
            self::assertStringNotContainsString('changefreq', (string) $response->getContent(), $path);
        }
    }

    public function test_a_file_past_the_end_is_a_404(): void
    {
        DetailVenues::minimal();

        foreach (['/sitemap-venues-2.xml', '/sitemap-venues-0.xml', '/sitemap-venues-99999999999999999999.xml'] as $path) {
            $this->client->request('GET', $path);

            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function test_the_schema_can_fail(): void
    {
        DetailVenues::minimal();
        $xml = $this->fetch('/sitemap-venues-1.xml');
        $lastmod = $xml->getElementsByTagNameNS(self::SITEMAP_NS, 'lastmod')->item(0);
        self::assertNotNull($lastmod);
        $lastmod->textContent = 'gestern';

        self::assertFalse($this->isValid($xml, 'sitemap-with-images.xsd'));
    }

    private function setUpdatedAt(string $table, string $column, string $id, string $at): void
    {
        $this->connection->executeStatement(\sprintf('UPDATE %s SET updated_at = :at WHERE %s = :id', $table, $column), ['at' => $at, 'id' => $id]);
    }

    private function fetch(string $path): \DOMDocument
    {
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful($path);

        $xml = new \DOMDocument();
        self::assertTrue($xml->loadXML((string) $this->client->getResponse()->getContent()), $path);

        return $xml;
    }

    /**
     * @return list<string>
     */
    private function values(\DOMDocument $xml, string $name, string $namespace): array
    {
        $values = [];
        foreach ($xml->getElementsByTagNameNS($namespace, $name) as $element) {
            $values[] = $element->textContent;
        }

        return $values;
    }

    private function assertValid(\DOMDocument $xml, string $schema): void
    {
        self::assertTrue($this->isValid($xml, $schema), implode("\n", array_map(
            static fn (\LibXMLError $error): string => $error->message,
            libxml_get_errors(),
        )));
    }

    private function isValid(\DOMDocument $xml, string $schema): bool
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            return $xml->schemaValidate(self::SCHEMAS.$schema);
        } finally {
            libxml_use_internal_errors($previous);
        }
    }
}
