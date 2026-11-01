<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Ähnliche Locations" on the detail page (#37, DP-15). The client shares the test's connection (no reboot), so
 * the venues created here are visible to the request and rolled back afterwards.
 */
final class SimilarVenuesTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

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

    public function test_the_band_has_one_named_region(): void
    {
        $crawler = $this->detailPage();

        self::assertCount(0, $crawler->filter('.similar-venues[aria-labelledby]'));
        self::assertCount(0, $crawler->filter('#similar-venues-title'));

        $named = $crawler->filter('.similar-venues section[aria-label]');
        self::assertCount(1, $named);
        self::assertSame('Ähnliche Locations', $named->attr('aria-label'));
    }

    public function test_the_facts_line_leads_with_the_place(): void
    {
        $facts = $this->detailPage()->filter('.similar-card .card-facts')->each(static fn (Crawler $line): string => $line->text());

        self::assertContains('Zürich · 50 - 100 Personen', $facts);
        self::assertContains('Winterthur · 80 Personen', $facts);
    }

    public function test_a_venue_without_a_town_shows_the_capacity_only(): void
    {
        $crawler = $this->detailPage();

        $line = $crawler->filter('.similar-card')
            ->reduce(static fn (Crawler $card): bool => str_contains($card->text(), 'Wiesenhof Ohne Ort'))
            ->filter('p.card-facts.similar-card__facts');

        self::assertCount(1, $line);
        self::assertSame('80 Personen', $line->text());
    }

    public function test_the_band_links_to_every_location(): void
    {
        $crawler = $this->detailPage();

        self::assertSelectorTextContains('.similar-venues__all', 'Alle Locations');
        self::assertSame('/hochzeitslocations', $crawler->filter('.similar-venues__all')->attr('href'));
        self::assertCount(1, $crawler->filter('.similar-venues__all .icon'));
    }

    private function detailPage(): Crawler
    {
        $this->venue('ich-selbst', 'Ich Selbst', 'Zürich', 20, 60);
        $this->venue('andere-in-zuerich', 'Andere in Zürich', 'Zürich', 50, 100);
        $this->venue('andere-in-winterthur', 'Andere in Winterthur', 'Winterthur', null, 80);
        DetailVenues::withoutPlace();

        $crawler = $this->client->request('GET', '/hochzeitslocation/ich-selbst');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function venue(string $slug, string $name, string $town, ?int $min, int $max): void
    {
        $venue = VenueFactory::createOne([
            'name' => $name,
            'tier' => Tier::Basic,
            'status' => VenueStatus::Published,
            'town' => DetailVenues::town($town),
            'capacityMin' => $min,
            'capacityMax' => $max,
        ]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => $slug]);
    }
}
