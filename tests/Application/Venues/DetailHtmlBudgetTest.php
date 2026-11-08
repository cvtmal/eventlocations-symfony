<?php

declare(strict_types=1);

namespace App\Tests\Application\Venues;

use App\Factory\LastMinuteOfferFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\LastMinuteLimits;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * S11, §4a.9: the detail page stays ≤ 60 KB gzip with the most a Pro entry can add in G14: an overfull venue (every
 * room, photo and document of the fixture) with `LastMinuteLimits::MAX_LIVE` offers, every field at its longest. Until
 * G14 the detail page had no HTML budget of its own. SEARCH_PERF_REPORT=1 prints the size.
 */
#[Group('budget')]
final class DetailHtmlBudgetTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const int BUDGET_BYTES = 60 * 1024;

    private ?KernelBrowser $client = null;
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
        $this->client = null;
        parent::tearDown();
    }

    public function test_an_overfull_pro_entry_with_three_offers_stays_within_the_gzip_budget(): void
    {
        $venue = DetailVenues::overfull(KnownTiers::PRO);
        $codes = array_map(strval(...), $this->connection->fetchFirstColumn("SELECT term_code FROM venue_terms WHERE venue_id = ? AND term_group = 'suitable_for'", [$venue->id()]));
        $day = new \DateTimeImmutable('today +3 days', new \DateTimeZone('Europe/Zurich'));
        for ($i = 0; $i < LastMinuteLimits::MAX_LIVE; ++$i) {
            LastMinuteOfferFactory::createOne([
                'venue' => $venue,
                'startsOn' => $day->modify(\sprintf('+%d days', $i)),
                'endsOn' => $day->modify(\sprintf('+%d days', $i + LastMinuteLimits::MAX_DAYS - 1)),
                'eventTypes' => $codes,
                'priceText' => str_pad(\sprintf('Paket %d ', $i), LastMinuteLimits::PRICE_TEXT_MAX, 'Seminar Apéro Bankett '),
                'minGuests' => 1200,
                'terms' => str_pad(\sprintf('Bedingung %d ', $i), LastMinuteLimits::TERMS_MAX, 'Nur werktags, Anfrage bis drei Tage vorher, '),
            ]);
        }

        \assert(null !== $this->client);
        $crawler = $this->client->request('GET', '/eventlokal/'.DetailVenues::OVERFULL_SLUG.'?_profile=0');

        self::assertResponseIsSuccessful();
        self::assertCount(LastMinuteLimits::MAX_LIVE, $crawler->filter('#kurzfristig-frei .last-minute-offer'));
        $html = (string) $this->client->getResponse()->getContent();
        $gzip = \strlen((string) gzencode($html, 9));
        if ('1' === getenv('SEARCH_PERF_REPORT')) {
            fwrite(\STDERR, \sprintf("[html-budget] %-12s %6d B gzip (%d raw) of %d\n", 'Detail', $gzip, \strlen($html), self::BUDGET_BYTES));
        }
        self::assertLessThanOrEqual(self::BUDGET_BYTES, $gzip, \sprintf('The detail page is %d bytes gzip (%d raw), budget %d.', $gzip, \strlen($html), self::BUDGET_BYTES));
    }
}
