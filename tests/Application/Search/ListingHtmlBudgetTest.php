<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueRatingHighlightFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Search\ReadModel\ListingQuery;
use App\Search\ReadModel\SearchCriteria;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\RatingHighlight;
use App\Venues\ValueObject\Tier;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * S11: the listing HTML stays ≤ 60 KB gzip in the worst case, a full page of 48 cards with
 * eight photos (three widths × three formats each), a 200-character summary, a rating with
 * three highlights and a badge, with the "weitere Filter" overlay full: one venue carries every
 * value, so 72 checkboxes (69 terms, 3 access) and 21 options render.
 */
final class ListingHtmlBudgetTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const BUDGET_BYTES = 60 * 1024;

    public function test_a_full_page_stays_within_the_gzip_budget(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->beginTransaction();

        try {
            for ($i = 1; $i <= ListingQuery::PER_PAGE; ++$i) {
                $factory = VenueFactory::new()->rated('8.7', 1234);
                if (1 === $i) {
                    $factory = $factory->withRoomFacts(6, 300, '5.0')
                        ->afterInstantiate(static fn (Venue $venue) => $venue->changeDistances('0.5', 'Bahnhof', '5.0', null, '20.0', null));
                }
                $venue = $factory->create([
                    'name' => 'Schlossgut Hohenrain am See '.$i,
                    'tier' => Tier::TopPremium,
                    'capacityMin' => 40,
                    'capacityMax' => 1200,
                ]);
                VenueTranslationFactory::createOne(['venue' => $venue, 'summary' => mb_substr(str_repeat('Hochzeit am See mit Garten. ', 8), 0, 200)]);
                foreach ([RatingHighlight::Food, RatingHighlight::Atmosphere, RatingHighlight::Architecture] as $position => $code) {
                    VenueRatingHighlightFactory::createOne(['venue' => $venue, 'position' => $position + 1, 'code' => $code]);
                }
                for ($position = 1; $position <= 8; ++$position) {
                    VenuePhotoFactory::createOne(['venue' => $venue, 'position' => $position]);
                }
                if (1 === $i) {
                    $this->giveEveryTerm($connection, $venue);
                }
            }

            $crawler = $client->request('GET', '/hochzeitslocations?_profile=0');
            self::assertResponseIsSuccessful();
            self::assertCount(ListingQuery::PER_PAGE, $crawler->filter('.card'));
            self::assertCount(ListingQuery::PER_PAGE * 8, $crawler->filter('.listing__results img'));
            self::assertCount(72, $crawler->filter('#filter-more input[type="checkbox"]'));
            self::assertCount(21, $crawler->filter('#filter-more option'));

            $html = (string) $client->getResponse()->getContent();
            // The web debug toolbar is injected in dev only; the test env renders the page alone.
            $gzip = \strlen((string) gzencode($html, 9));
            self::assertLessThanOrEqual(self::BUDGET_BYTES, $gzip, \sprintf('Listing HTML is %d bytes gzip (%d raw), budget %d.', $gzip, \strlen($html), self::BUDGET_BYTES));
        } finally {
            $connection->rollBack();
        }
    }

    private function giveEveryTerm(Connection $connection, Venue $venue): void
    {
        foreach (SearchCriteria::PARAMS as $group) {
            foreach ($connection->fetchFirstColumn('SELECT code FROM taxonomy_terms WHERE term_group = ?', [$group->value]) as $code) {
                self::assertIsString($code);
                VenueTermFactory::new()->term($group, $code)->create(['venue' => $venue]);
            }
        }
    }
}
