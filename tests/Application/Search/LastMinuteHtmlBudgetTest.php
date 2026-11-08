<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Factory\LastMinuteOfferFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\Entity\Town;
use App\Search\ReadModel\LastMinutePageQuery;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueTranslation;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\LastMinuteLimits;
use App\Venues\ValueObject\PriceLevel;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * S11, §4a.9: the page «Kurzfristig frei» (G14) stays ≤ 60 KB gzip in its worst case: LIMIT offers of LIMIT venues, so
 * LIMIT full cards (one photo each, a 200-character summary, both tags, a 60-character name, the badge), each offer
 * with every field at its longest. SEARCH_PERF_REPORT=1 prints the size.
 */
#[Group('budget')]
final class LastMinuteHtmlBudgetTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const int BUDGET_BYTES = 60 * 1024;

    /** Words for texts that do not compress better than real copy would. */
    private const array WORDS = [
        'Seminar', 'Apéro', 'Seeufer', 'Garten', 'Scheune', 'Festsaal', 'Firmenanlass', 'Bankett', 'Weinkeller', 'Terrasse',
        'Workshop', 'Gäste', 'Tanzfläche', 'Kerzenlicht', 'Alpenblick', 'Schlosspark', 'Menü', 'Dessertbuffet', 'Beamer', 'Blumen',
        'ländlich', 'elegant', 'festlich', 'pauschal', 'grosszügig', 'ruhig', 'historisch', 'modern', 'sonnig', 'gemütlich',
    ];

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

    public function test_the_full_page_stays_within_the_gzip_budget(): void
    {
        $town = repository(Town::class)->findOneBy(['name' => 'Bern']);
        self::assertInstanceOf(Town::class, $town);
        $day = new \DateTimeImmutable('today +3 days', new \DateTimeZone('Europe/Zurich'));
        $codes = array_map(strval(...), $this->connection->fetchFirstColumn("SELECT code FROM taxonomy_terms WHERE term_group = 'suitable_for' ORDER BY position LIMIT 4"));
        for ($i = 0; $i < LastMinutePageQuery::LIMIT; ++$i) {
            $venue = $this->fullVenue($i, $town);
            foreach ($codes as $code) {
                $this->connection->executeStatement("INSERT INTO venue_terms (venue_id, term_group, term_code, is_main) VALUES (?, 'suitable_for', ?, FALSE)", [$venue->id(), $code]);
            }
            LastMinuteOfferFactory::createOne([
                'venue' => $venue,
                'startsOn' => $day,
                'endsOn' => $day->modify(\sprintf('+%d days', LastMinuteLimits::MAX_DAYS - 1)),
                'eventTypes' => $codes,
                'priceText' => $this->text(LastMinuteLimits::PRICE_TEXT_MAX, 2000 + $i),
                'minGuests' => 1200,
                'terms' => $this->text(LastMinuteLimits::TERMS_MAX, 3000 + $i),
            ]);
        }

        \assert(null !== $this->client);
        $crawler = $this->client->request('GET', '/kurzfristig-frei?_profile=0');

        self::assertResponseIsSuccessful();
        self::assertCount(LastMinutePageQuery::LIMIT, $crawler->filter('.last-minute__entry .card'));
        self::assertCount(LastMinutePageQuery::LIMIT, $crawler->filter('.last-minute-offer'));
        $html = (string) $this->client->getResponse()->getContent();
        $gzip = \strlen((string) gzencode($html, 9));
        if ('1' === getenv('SEARCH_PERF_REPORT')) {
            fwrite(\STDERR, \sprintf("[html-budget] %-12s %6d B gzip (%d raw) of %d\n", 'Last minute', $gzip, \strlen($html), self::BUDGET_BYTES));
        }
        self::assertLessThanOrEqual(self::BUDGET_BYTES, $gzip, \sprintf('The page is %d bytes gzip (%d raw), budget %d.', $gzip, \strlen($html), self::BUDGET_BYTES));
    }

    private function fullVenue(int $i, Town $town): Venue
    {
        $ambience = mb_substr(str_repeat('Romantisch und ländlich am See. ', 2), 0, 60);
        $venue = VenueFactory::new()
            ->afterInstantiate(static fn (Venue $venue) => $venue->changePriceLevel(PriceLevel::cases()[$i % 3]))
            ->create(['name' => mb_substr(\sprintf('%03d ', $i).$this->text(80, 1000 + $i), 0, 60), 'capacityMin' => 40, 'capacityMax' => 1200, 'town' => $town, 'tier' => KnownTiers::PRO]);
        VenueTranslationFactory::new()
            ->afterInstantiate(static fn (VenueTranslation $translation) => $translation->describeEvent($ambience, null, null))
            ->create(['venue' => $venue, 'summary' => $this->text(200, $i)]);
        foreach ([1, 2] as $position) {
            VenuePhotoFactory::createOne(['venue' => $venue, 'position' => $position]);
        }

        return $venue;
    }

    /** Seeded words, cut to at most $length code points, ending in a full stop. */
    private function text(int $length, int $seed): string
    {
        mt_srand($seed);
        $text = '';
        while (mb_strlen($text) < $length) {
            $text .= self::WORDS[mt_rand(0, \count(self::WORDS) - 1)].' ';
        }

        return rtrim(mb_substr($text, 0, $length - 1)).'.';
    }
}
