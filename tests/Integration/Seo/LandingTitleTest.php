<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Geo\ValueObject\Canton;
use App\Search\LandingPaths;
use App\Search\ReadModel\KnownTerms;
use App\Search\ReadModel\KnownTermsQuery;
use App\Search\ReadModel\LandingFooter;
use App\Search\ReadModel\LandingPage;
use App\Search\ReadModel\LandingPages;
use App\Search\ReadModel\ListingPage;
use App\Search\ReadModel\ListingQuery;
use App\Search\ReadModel\SearchCriteria;
use App\Seo\ListingMeta;
use App\Seo\TitleChain;
use App\Seo\ValueObject\PageMeta;
use App\Shared\Markdown\MarkdownRenderer;
use App\Shared\ReadModel\ContentBlocks;
use App\Tests\Integration\Search\ListingQueryTest;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * SEO-1 on the landing pages (#17, D13 and C2): the titles and descriptions ListingMeta gives
 * every canton and building page, at any count and page. The pages are hand-made rows of
 * LandingPages' statement (a stubbed connection), so a count of 999 needs no venues; only the
 * vocabulary (`taxonomy_terms`) is read from app_test.
 */
final class LandingTitleTest extends KernelTestCase
{
    /** D3: the venues per canton at launch. */
    private const array LAUNCH_COUNTS = [
        'zh' => 52, 'be' => 34, 'lu' => 31, 'sg' => 19, 'tg' => 14, 'ag' => 13, 'sz' => 9,
        'vs' => 8, 'so' => 8, 'gr' => 8, 'ow' => 7, 'nw' => 6, 'sh' => 5,
    ];

    /** The building pages #32's import is forecast to switch on, with their forecast counts. */
    private const array FORECAST_BUILDINGS = [
        'experience_creative' => 85, 'hotel' => 66, 'restaurant' => 51, 'event_hall' => 18, 'castle' => 16, 'brewery_winery' => 5,
    ];

    private TranslatorInterface $translator;
    private UrlGeneratorInterface $urls;
    private KnownTerms $known;
    private TitleChain $titles;
    private string $site;

    protected function setUp(): void
    {
        $translator = self::getContainer()->get('translator');
        $urls = self::getContainer()->get('router');
        $connection = self::getContainer()->get(Connection::class);
        $site = self::getContainer()->getParameter('site.name');
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        self::assertInstanceOf(UrlGeneratorInterface::class, $urls);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertIsString($site);
        $this->translator = $translator;
        $this->urls = $urls;
        $this->known = new KnownTermsQuery($connection)->all();
        $this->titles = new TitleChain($translator, $site);
        $this->site = $site;
    }

    public function test_every_canton_title_fits_at_any_count_and_page(): void
    {
        foreach ([5, 52, 999] as $count) {
            [$pages, $meta] = $this->landing(array_map(
                static fn (Canton $canton): array => self::cantonRow($canton, $count),
                Canton::cases(),
            ));
            $lastPage = (int) ceil($count / ListingQuery::PER_PAGE);

            foreach (Canton::cases() as $canton) {
                $page = $pages->canton($canton);
                self::assertNotNull($page, $canton->value);
                foreach (array_unique([1, 2, $lastPage]) as $number) {
                    $this->assertFits($meta->for($this->criteria($page, $number), $this->listingPage($count, $number), $page), $canton->value.' '.$count.' p'.$number);
                }
            }
        }
    }

    public function test_the_thirteen_launch_titles(): void
    {
        [$pages, $meta] = $this->landing(array_map(
            static fn (string $code, int $count): array => self::cantonRow(Canton::from($code), $count),
            array_keys(self::LAUNCH_COUNTS),
            self::LAUNCH_COUNTS,
        ));

        $titles = [];
        foreach (array_keys(self::LAUNCH_COUNTS) as $code) {
            $page = $pages->canton(Canton::from($code));
            self::assertNotNull($page, $code);
            $titles[$code] = $meta->for($this->criteria($page, 1), $this->listingPage($page->venues, 1), $page)->title;
        }

        // C2, with the 22-character site name: no canton keeps its count; six take the second pattern,
        // Thurgau, Solothurn, Obwalden and Nidwalden the third, St. Gallen, Graubünden and Schaffhausen the fourth.
        self::assertSame([
            'zh' => 'Hochzeitslocations im Kanton Zürich | deine-eventlocation.ch',
            'be' => 'Hochzeitslocations im Kanton Bern | deine-eventlocation.ch',
            'lu' => 'Hochzeitslocations im Kanton Luzern | deine-eventlocation.ch',
            'sg' => 'Hochzeitslocations St. Gallen | deine-eventlocation.ch',
            'tg' => 'Hochzeitslocations Kanton Thurgau | deine-eventlocation.ch',
            'ag' => 'Hochzeitslocations im Kanton Aargau | deine-eventlocation.ch',
            'sz' => 'Hochzeitslocations im Kanton Schwyz | deine-eventlocation.ch',
            'vs' => 'Hochzeitslocations im Kanton Wallis | deine-eventlocation.ch',
            'so' => 'Hochzeitslocations Kanton Solothurn | deine-eventlocation.ch',
            'gr' => 'Hochzeitslocations Graubünden | deine-eventlocation.ch',
            'ow' => 'Hochzeitslocations Kanton Obwalden | deine-eventlocation.ch',
            'nw' => 'Hochzeitslocations Kanton Nidwalden | deine-eventlocation.ch',
            'sh' => 'Hochzeitslocations Schaffhausen | deine-eventlocation.ch',
        ], $titles);
    }

    public function test_the_zurich_page_two_title_is_numbered(): void
    {
        [$pages, $meta] = $this->landing([self::cantonRow(Canton::ZH, 52)]);
        $page = $pages->canton(Canton::ZH);
        self::assertNotNull($page);

        self::assertSame(
            'Hochzeitslocations Zürich – Seite 2 | deine-eventlocation.ch',
            $meta->for($this->criteria($page, 2), $this->listingPage(52, 2), $page)->title,
        );
    }

    public function test_every_building_title_fits_at_any_count_and_page(): void
    {
        $codes = $this->buildingCodes();
        self::assertCount(38, $codes);

        foreach ([5, 52, 999] as $count) {
            [$pages, $meta] = $this->landing(array_map(static fn (string $code): array => self::buildingRow($code, $count), $codes));
            $lastPage = (int) ceil($count / ListingQuery::PER_PAGE);

            foreach ($codes as $code) {
                $page = $pages->building($code);
                self::assertNotNull($page, $code);
                foreach (array_unique([1, 2, $lastPage]) as $number) {
                    $this->assertFits($meta->for($this->criteria($page, $number), $this->listingPage($count, $number), $page), $code.' '.$count.' p'.$number);
                }
            }
        }
    }

    public function test_the_forecast_building_pages_never_need_the_last_resort(): void
    {
        [$pages, $meta] = $this->landing(array_map(
            static fn (string $code, int $count): array => self::buildingRow($code, $count),
            array_keys(self::FORECAST_BUILDINGS),
            self::FORECAST_BUILDINGS,
        ));

        foreach (self::FORECAST_BUILDINGS as $code => $count) {
            $page = $pages->building($code);
            self::assertNotNull($page, $code);
            $heading = $this->translator->trans('landing.building.'.$code.'.heading', [], 'search');
            if ('' === $heading) {
                self::fail($code.': no heading');
            }
            $lastResort = $this->titles->fit([], $heading);

            for ($number = 1; $number <= (int) ceil($count / ListingQuery::PER_PAGE); ++$number) {
                $title = $meta->for($this->criteria($page, $number), $this->listingPage($count, $number), $page)->title;

                self::assertNotSame($lastResort, $title, $code.' p'.$number.': '.$title);
                self::assertStringNotContainsString('…', $title, $code.' p'.$number);
                self::assertStringStartsWith($heading, $title, $code.' p'.$number);
                if ($number > 1) {
                    self::assertStringContainsString('Seite '.$number, $title, $code.' p'.$number);
                }
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $rows rows of LandingPages::statement()
     *
     * @return array{LandingPages, ListingMeta}
     */
    private function landing(array $rows): array
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($rows);
        $pages = new LandingPages($connection, ListingQueryTest::enabledLocale(), $this->translator, new LandingFooter(new ArrayAdapter()), 5);

        // An empty, warm content-block snapshot (#26): no staff text, and no read of the stub.
        $clock = new MockClock();
        $blocks = new ArrayAdapter();
        $blocks->save($blocks->getItem(ContentBlocks::ITEM)->set(['built_at' => $clock->now()->getTimestamp(), 'blocks' => []]));

        return [$pages, new ListingMeta($this->translator, new LandingPaths($pages, $this->urls), $this->titles, new ContentBlocks($connection, $blocks, new MarkdownRenderer(), $clock), $this->site)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function cantonRow(Canton $canton, int $venues): array
    {
        return ['type' => 'canton', 'code' => $canton->value, 'slug' => 'kanton-'.$canton->value, 'canton_slug' => 'kanton-'.$canton->value, 'canton' => $canton->value, 'town_name' => null, 'venues' => $venues];
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildingRow(string $code, int $venues): array
    {
        return ['type' => 'building', 'code' => $code, 'slug' => null, 'canton_slug' => null, 'canton' => null, 'town_name' => null, 'venues' => $venues];
    }

    private function criteria(LandingPage $page, int $number): SearchCriteria
    {
        /** @var InputBag<string> $query */
        $query = new InputBag($page->facetQuery() + ['page' => (string) $number]);

        return SearchCriteria::fromQuery($query, $this->known);
    }

    private function listingPage(int $total, int $number): ListingPage
    {
        return ListingPage::of([], $total, $number, ListingQuery::PER_PAGE);
    }

    private function assertFits(PageMeta $meta, string $case): void
    {
        self::assertLessThanOrEqual(TitleChain::MAX, mb_strlen($meta->title), $case.': '.$meta->title);
        // A numbered page that would not fit with the site name drops it (title_page_bare) and keeps its number.
        self::assertMatchesRegularExpression('/( \| '.preg_quote($this->site, '/').'| – Seite \d+)$/u', $meta->title, $case);
        self::assertLessThanOrEqual(155, mb_strlen($meta->description), $case.': '.$meta->description);
        self::assertGreaterThan(0, mb_strlen($meta->description), $case);
        self::assertFalse($meta->noindex, $case);
    }

    /**
     * @return list<string>
     */
    private function buildingCodes(): array
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        /* @var list<string> */
        return $connection->fetchFirstColumn("SELECT code FROM taxonomy_terms WHERE term_group = 'building' ORDER BY code");
    }
}
