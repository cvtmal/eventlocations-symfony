<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\Entity\Town;
use App\Search\Extension\ListingIntroBlock;
use App\Search\ReadModel\ListingQuery;
use App\Search\ReadModel\SearchCriteria;
use App\Shared\Extension\FooterNoteBlock;
use App\Shared\ReadModel\ContentBlocks;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Cms\ResetsContentBlocks;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueTranslation;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\Tier;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * S11: the listing HTML stays ≤ 60 KB gzip in the worst case, a full page of 48 cards with
 * eight photos (three widths × three formats each), a 200-character summary, both tags (a price
 * level, all three across the page, and a 60-character ambience) and a badge, with the "weitere Filter" overlay full: one venue carries every value, so 79
 * checkboxes (73 terms, 3 access, 3 price) and 21 options render. All 48 venues sit in
 * different towns of one canton, so the same page also renders as that canton's landing page
 * (#17: breadcrumb, intro, canton chip, its JSON-LD), which must fit the same budget. Staff
 * texts (#26) are at their maximum: a 600-character listing intro and canton intro, the
 * canton's 60-character title and 155-character description, and a 300-character footer note.
 * SEARCH_PERF_REPORT=1 prints both sizes.
 */
#[Group('budget')]
final class ListingHtmlBudgetTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use ResetsContentBlocks;

    private const BUDGET_BYTES = 60 * 1024;

    /** Words for staff texts that do not compress better than real copy would. */
    private const WORDS = [
        'Hochzeit', 'Apéro', 'Seeufer', 'Garten', 'Scheune', 'Festsaal', 'Trauung', 'Bankett', 'Weinkeller', 'Terrasse',
        'Brautpaar', 'Gäste', 'Tanzfläche', 'Kerzenlicht', 'Alpenblick', 'Schlosspark', 'Menü', 'Dessertbuffet', 'Fotograf', 'Blumen',
        'ländlich', 'elegant', 'festlich', 'romantisch', 'grosszügig', 'ruhig', 'historisch', 'modern', 'sonnig', 'gemütlich',
        'feiern', 'staunen', 'geniessen', 'tanzen', 'anstossen', 'übernachten', 'planen', 'vergleichen', 'entdecken', 'buchen',
    ];

    public function test_a_full_page_stays_within_the_gzip_budget(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->beginTransaction();
        $this->resetContentBlocks();

        try {
            $towns = $connection->fetchFirstColumn('SELECT id FROM towns WHERE canton_code = ? ORDER BY name LIMIT ?', ['zh', ListingQuery::PER_PAGE]);
            self::assertCount(ListingQuery::PER_PAGE, $towns);
            $ambience = mb_substr(str_repeat('Romantisch und ländlich am See. ', 2), 0, 60);
            for ($i = 1; $i <= ListingQuery::PER_PAGE; ++$i) {
                $price = PriceLevel::cases()[$i % 3];
                $factory = VenueFactory::new()
                    ->afterInstantiate(static fn (Venue $venue) => $venue->changePriceLevel($price));
                if (1 === $i) {
                    $factory = $factory->withRoomFacts(6, 300, '5.0')
                        ->afterInstantiate(static fn (Venue $venue) => $venue->changeDistances('0.5', 'Bahnhof', '5.0', null, '20.0', null));
                }
                $venue = $factory->create([
                    'name' => 'Schlossgut Hohenrain am See '.$i,
                    'tier' => Tier::TopPremium,
                    'capacityMin' => 40,
                    'capacityMax' => 1200,
                    'town' => repository(Town::class)->find($towns[$i - 1]),
                ]);
                VenueTranslationFactory::new()
                    ->afterInstantiate(static fn (VenueTranslation $translation) => $translation->describeEvent($ambience, null, null))
                    ->create(['venue' => $venue, 'summary' => mb_substr(str_repeat('Hochzeit am See mit Garten. ', 8), 0, 200)]);
                for ($position = 1; $position <= 8; ++$position) {
                    VenuePhotoFactory::createOne(['venue' => $venue, 'position' => $position]);
                }
                if (1 === $i) {
                    $this->giveEveryTerm($connection, $venue);
                }
            }

            $this->storeMaximalBlocks($connection);

            $crawler = $this->assertWithinBudget($client, '/hochzeitslocations', 'Listing');
            self::assertCount(1, $crawler->filter('.listing-hero__intro strong'), 'The listing intro renders.');

            $crawler = $this->assertWithinBudget($client, '/hochzeitslocations/zuerich', 'Canton page');
            self::assertSelectorTextSame('.listing-hero__title', 'Hochzeitslocations im Kanton Zürich');
            self::assertCount(1, $crawler->filter('nav.breadcrumb'));
            self::assertCount(1, $crawler->filter('.listing-hero__intro strong'), 'The canton intro renders.');
        } finally {
            $connection->rollBack();
            $this->resetContentBlocks();
        }
    }

    private function assertWithinBudget(KernelBrowser $client, string $path, string $what): Crawler
    {
        $crawler = $client->request('GET', $path.'?_profile=0');
        self::assertResponseIsSuccessful($path);
        self::assertCount(ListingQuery::PER_PAGE, $crawler->filter('.card'));
        self::assertCount(ListingQuery::PER_PAGE * 8, $crawler->filter('.listing__results img'));
        self::assertCount(ListingQuery::PER_PAGE * 2, $crawler->filter('.card__tags .tag'));
        self::assertCount(79, $crawler->filter('#filter-more input[type="checkbox"]'));
        self::assertCount(21, $crawler->filter('#filter-more option'));

        $html = (string) $client->getResponse()->getContent();
        // The web debug toolbar is injected in dev only; the test env renders the page alone.
        $gzip = \strlen((string) gzencode($html, 9));
        if ('1' === getenv('SEARCH_PERF_REPORT')) {
            fwrite(\STDERR, \sprintf("[html-budget] %-12s %6d B gzip (%d raw) of %d\n", $what, $gzip, \strlen($html), self::BUDGET_BYTES));
        }
        self::assertLessThanOrEqual(self::BUDGET_BYTES, $gzip, \sprintf('%s HTML is %d bytes gzip (%d raw), budget %d.', $what, $gzip, \strlen($html), self::BUDGET_BYTES));

        return $crawler;
    }

    private function storeMaximalBlocks(Connection $connection): void
    {
        $bodies = [
            ListingIntroBlock::KEY => $this->markdown(600, 1),
            'landing.canton.zh.intro' => $this->markdown(600, 2),
            'landing.canton.zh.title' => $this->text(60, 3),
            'landing.canton.zh.description' => $this->text(155, 4),
            FooterNoteBlock::KEY => $this->markdown(300, 5),
        ];
        foreach ($bodies as $key => $body) {
            $connection->insert('content_blocks', ['key' => $key, 'locale' => 'de', 'body_markdown' => $body, 'updated_at' => '2026-10-31 08:00:00+00']);
        }

        $blocks = self::getContainer()->get(ContentBlocks::class);
        self::assertInstanceOf(ContentBlocks::class, $blocks);
        $blocks->refresh();
    }

    /** Two paragraphs with bold text and a link, at most $length code points. */
    private function markdown(int $length, int $seed): string
    {
        $head = '**'.$this->text(30, $seed).'** [Mehr](/hochzeitslocations) ';
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
