<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueRatingHighlightFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\Entity\Town;
use App\Search\ReadModel\NearbyVenuesQuery;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueTranslation;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\RatingHighlight;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * S11: the near-me page's HTML stays ≤ 60 KB gzip in both of its worst cases (#52), with a key, so the pins are in it:
 * LIMIT full cards within 30 km (one photo each, a 200-character summary, both tags, a rating with three highlights,
 * a 60-character name), and the whole-Switzerland map with ALL_CAP pins of 60-character names.
 * SEARCH_PERF_REPORT=1 prints the sizes.
 */
#[Group('budget')]
final class NearbyHtmlBudgetTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const int BUDGET_BYTES = 60 * 1024;
    private const string URL = '/hochzeitslocation-in-meiner-naehe';

    /** Words for summaries that do not compress better than real copy would. */
    private const array WORDS = [
        'Hochzeit', 'Apéro', 'Seeufer', 'Garten', 'Scheune', 'Festsaal', 'Trauung', 'Bankett', 'Weinkeller', 'Terrasse',
        'Brautpaar', 'Gäste', 'Tanzfläche', 'Kerzenlicht', 'Alpenblick', 'Schlosspark', 'Menü', 'Dessertbuffet', 'Fotograf', 'Blumen',
        'ländlich', 'elegant', 'festlich', 'romantisch', 'grosszügig', 'ruhig', 'historisch', 'modern', 'sonnig', 'gemütlich',
    ];

    private ?KernelBrowser $client = null;
    private Connection $connection;
    private mixed $serverKey = null;
    private mixed $envKey = null;

    protected function setUp(): void
    {
        $this->serverKey = $_SERVER['GOOGLE_MAPS_JS_KEY'] ?? null;
        $this->envKey = $_ENV['GOOGLE_MAPS_JS_KEY'] ?? null;
        $_SERVER['GOOGLE_MAPS_JS_KEY'] = $_ENV['GOOGLE_MAPS_JS_KEY'] = 'budget-test-key';

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
        $_SERVER['GOOGLE_MAPS_JS_KEY'] = $this->serverKey;
        $_ENV['GOOGLE_MAPS_JS_KEY'] = $this->envKey;
    }

    public function test_the_full_list_within_30_km_stays_within_the_gzip_budget(): void
    {
        $town = $this->town('Stäfa');
        for ($i = 0; $i < NearbyVenuesQuery::LIMIT; ++$i) {
            // A 10 × 10 grid of 0.01°, about 1 km apart, all within 15 km of the centre.
            $this->fullVenue($i, $town, 47.24 + intdiv($i, 10) * 0.01, 8.72 + ($i % 10) * 0.01);
        }

        \assert(null !== $this->client);
        $crawler = $this->client->request('GET', self::URL.'?lat=47.28&lng=8.76&_profile=0');

        self::assertResponseIsSuccessful();
        self::assertCount(NearbyVenuesQuery::LIMIT, $crawler->filter('.listing__results .card'));
        self::assertCount(NearbyVenuesQuery::LIMIT, $crawler->filter('.listing__results .carousel__slide'), 'One photo per card.');
        self::assertCount(2 * NearbyVenuesQuery::LIMIT, $crawler->filter('.card__tags .tag'));
        $this->assertWithinBudget('Near me A');
    }

    public function test_the_whole_switzerland_map_stays_within_the_gzip_budget(): void
    {
        $town = $this->town('Bern');
        for ($i = 0; $i < NearbyVenuesQuery::ALL_CAP; ++$i) {
            // 20 × 20 points across the country's box, each its own pin.
            $venue = VenueFactory::createOne([
                'name' => $this->venueName($i),
                'town' => $town,
                'lat' => 45.9 + intdiv($i, 20) * 0.09,
                'lng' => 6.0 + ($i % 20) * 0.22,
            ]);
            VenueTranslationFactory::createOne(['venue' => $venue]);
        }

        \assert(null !== $this->client);
        $crawler = $this->client->request('GET', self::URL.'?_profile=0');

        self::assertResponseIsSuccessful();
        $pins = json_decode((string) $crawler->filter('[data-near-me-map]')->attr('data-near-me-pins'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($pins);
        self::assertCount(NearbyVenuesQuery::ALL_CAP, $pins);
        $this->assertWithinBudget('Near me B');
    }

    private function assertWithinBudget(string $label): void
    {
        \assert(null !== $this->client);
        $html = (string) $this->client->getResponse()->getContent();
        $gzip = \strlen((string) gzencode($html, 9));
        if ('1' === getenv('SEARCH_PERF_REPORT')) {
            fwrite(\STDERR, \sprintf("[html-budget] %-12s %6d B gzip (%d raw) of %d\n", $label, $gzip, \strlen($html), self::BUDGET_BYTES));
        }
        self::assertLessThanOrEqual(self::BUDGET_BYTES, $gzip, \sprintf('%s HTML is %d bytes gzip (%d raw), budget %d.', $label, $gzip, \strlen($html), self::BUDGET_BYTES));
    }

    private function fullVenue(int $i, Town $town, float $lat, float $lng): void
    {
        $ambience = mb_substr(str_repeat('Romantisch und ländlich am See. ', 2), 0, 60);
        $venue = VenueFactory::new()->rated('8.7', 1234)
            ->afterInstantiate(static fn (Venue $venue) => $venue->changePriceLevel(PriceLevel::cases()[$i % 3]))
            ->create(['name' => $this->venueName($i), 'capacityMin' => 40, 'capacityMax' => 1200, 'town' => $town, 'lat' => $lat, 'lng' => $lng]);
        VenueTranslationFactory::new()
            ->afterInstantiate(static fn (VenueTranslation $translation) => $translation->describeWedding($ambience, null, null, null))
            ->create(['venue' => $venue, 'summary' => $this->text(200, $i)]);
        foreach ([RatingHighlight::Food, RatingHighlight::Atmosphere, RatingHighlight::Architecture] as $position => $code) {
            VenueRatingHighlightFactory::createOne(['venue' => $venue, 'position' => $position + 1, 'code' => $code]);
        }
        foreach ([1, 2] as $position) {
            VenuePhotoFactory::createOne(['venue' => $venue, 'position' => $position]);
        }
    }

    /** 60 code points, different for every venue. */
    private function venueName(int $i): string
    {
        return mb_substr(\sprintf('%03d ', $i).$this->text(80, 1000 + $i), 0, 60);
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

    private function town(string $name): Town
    {
        $town = repository(Town::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Town::class, $town);

        return $town;
    }
}
