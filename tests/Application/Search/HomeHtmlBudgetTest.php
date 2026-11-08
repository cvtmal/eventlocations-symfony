<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\ValueObject\Canton;
use App\Search\Extension\HomeTextBlock;
use App\Shared\Extension\FooterNoteBlock;
use App\Shared\ReadModel\ContentBlocks;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Cms\ResetsContentBlocks;
use App\Tests\Support\Search\LandingFixtures;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueTranslation;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\Tier;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * S11: the home page's HTML stays ≤ 60 KB gzip in the worst case: all 27 regions live, three
 * Spotlight cards with a 200-character summary and both tags with a 60-character ambience, the `home.text` block at 1500 characters and the footer note at 300.
 * SEARCH_PERF_REPORT=1 prints the size.
 */
#[Group('budget')]
final class HomeHtmlBudgetTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use LandingFixtures;
    use ResetsContentBlocks;

    private const BUDGET_BYTES = 60 * 1024;
    private const LIVE_CANTON_VENUES = 5;

    /** Words for staff texts that do not compress better than real copy would. */
    private const WORDS = [
        'Hochzeit', 'Apéro', 'Seeufer', 'Garten', 'Scheune', 'Festsaal', 'Trauung', 'Bankett', 'Weinkeller', 'Terrasse',
        'Brautpaar', 'Gäste', 'Tanzfläche', 'Kerzenlicht', 'Alpenblick', 'Schlosspark', 'Menü', 'Dessertbuffet', 'Fotograf', 'Blumen',
        'ländlich', 'elegant', 'festlich', 'romantisch', 'grosszügig', 'ruhig', 'historisch', 'modern', 'sonnig', 'gemütlich',
        'feiern', 'staunen', 'geniessen', 'tanzen', 'anstossen', 'übernachten', 'planen', 'vergleichen', 'entdecken', 'buchen',
    ];

    public function test_the_worst_case_stays_within_the_gzip_budget(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->beginTransaction();
        $this->resetContentBlocks();

        try {
            foreach (Canton::cases() as $canton) {
                $this->inCanton($canton, self::LIVE_CANTON_VENUES);
            }
            for ($i = 1; $i <= 3; ++$i) {
                $this->spotlightVenue($i);
            }
            $this->storeMaximalBlocks($connection);

            $crawler = $client->request('GET', '/?_profile=0');

            self::assertResponseIsSuccessful();
            self::assertCount(3, $crawler->filter('.home-spotlight .card'));
            self::assertCount(6, $crawler->filter('.home-spotlight .card__tags .tag'));
            self::assertCount(27, $crawler->filter('#regionen a'));
            self::assertCount(1, $crawler->filter('.home-about__staff strong'), 'The staff text renders.');

            $html = (string) $client->getResponse()->getContent();
            $gzip = \strlen((string) gzencode($html, 9));
            if ('1' === getenv('SEARCH_PERF_REPORT')) {
                fwrite(\STDERR, \sprintf("[html-budget] %-12s %6d B gzip (%d raw) of %d\n", 'Home', $gzip, \strlen($html), self::BUDGET_BYTES));
            }
            self::assertLessThanOrEqual(self::BUDGET_BYTES, $gzip, \sprintf('Home HTML is %d bytes gzip (%d raw), budget %d.', $gzip, \strlen($html), self::BUDGET_BYTES));
        } finally {
            $connection->rollBack();
            $this->resetContentBlocks();
        }
    }

    /** Paid, so the three of them are Spotlight, whatever the day's order does with the cantons' Basic venues. */
    private function spotlightVenue(int $i): void
    {
        $ambience = mb_substr(str_repeat('Romantisch und ländlich am See. ', 2), 0, 60);
        $venue = VenueFactory::new()
            ->afterInstantiate(static fn (Venue $venue) => $venue->changePriceLevel(PriceLevel::cases()[$i % 3]))
            ->create([
                'name' => 'Schlossgut Hohenrain am See '.$i,
                'tier' => Tier::TopPremium,
                'capacityMin' => 40,
                'capacityMax' => 1200,
                'town' => $this->town('Stäfa'),
            ]);
        VenueTranslationFactory::new()
            ->afterInstantiate(static fn (VenueTranslation $translation) => $translation->describeEvent($ambience, null, null))
            ->create(['venue' => $venue, 'summary' => mb_substr(str_repeat('Hochzeit am See mit Garten. ', 8), 0, 200)]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
    }

    private function storeMaximalBlocks(Connection $connection): void
    {
        foreach ([HomeTextBlock::KEY => $this->markdown(1500, 1), FooterNoteBlock::KEY => $this->markdown(300, 5)] as $key => $body) {
            $connection->insert('content_blocks', ['key' => $key, 'locale' => 'de', 'body_markdown' => $body, 'updated_at' => '2026-10-31 08:00:00+00']);
        }

        $blocks = self::getContainer()->get(ContentBlocks::class);
        self::assertInstanceOf(ContentBlocks::class, $blocks);
        $blocks->refresh();
    }

    /** Two paragraphs with bold text and a link, at most $length code points. */
    private function markdown(int $length, int $seed): string
    {
        $head = '**'.$this->text(30, $seed).'** [Mehr](/eventlokale) ';
        $tail = "\n\n".$this->text(40, $seed + 10);

        return $head.$this->text($length - mb_strlen($head) - mb_strlen($tail), $seed + 20).$tail;
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
