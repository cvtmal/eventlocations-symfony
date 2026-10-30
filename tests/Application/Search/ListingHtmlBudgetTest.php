<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueRatingHighlightFactory;
use App\Factory\VenueTranslationFactory;
use App\Search\ReadModel\ListingQuery;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Venues\ValueObject\RatingHighlight;
use App\Venues\ValueObject\Tier;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * S11: the listing HTML stays ≤ 60 KB gzip in the worst case, a full page of 48 cards with
 * eight photos (three widths × three formats each), a 200-character summary, a rating with
 * three highlights and a badge.
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
                $venue = VenueFactory::new()->rated('8.7', 1234)->create([
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
            }

            $crawler = $client->request('GET', '/hochzeitslocations?_profile=0');
            self::assertResponseIsSuccessful();
            self::assertCount(ListingQuery::PER_PAGE, $crawler->filter('.card'));
            self::assertCount(ListingQuery::PER_PAGE * 8, $crawler->filter('.listing__results img'));

            $html = (string) $client->getResponse()->getContent();
            // The web debug toolbar is injected in dev only; the test env renders the page alone.
            $gzip = \strlen((string) gzencode($html, 9));
            self::assertLessThanOrEqual(self::BUDGET_BYTES, $gzip, \sprintf('Listing HTML is %d bytes gzip (%d raw), budget %d.', $gzip, \strlen($html), self::BUDGET_BYTES));
        } finally {
            $connection->rollBack();
        }
    }
}
