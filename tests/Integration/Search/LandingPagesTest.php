<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Factory\VenueFactory;
use App\Factory\VenueTermFactory;
use App\Geo\Entity\Canton as CantonRow;
use App\Geo\ValueObject\Canton;
use App\Search\ReadModel\LandingFooter;
use App\Search\ReadModel\LandingPage;
use App\Search\ReadModel\LandingPages;
use App\Search\ValueObject\LandingType;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Search\LandingFixtures;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * The live landing pages and the threshold that switches them on, with the container's
 * `%landing.min_venues%` (5 in test). Each test runs in a rolled-back transaction.
 */
final class LandingPagesTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;
    use LandingFixtures;

    private EntityManagerInterface $em;
    private Connection $connection;
    private LandingPages $pages;
    private ArrayAdapter $pool;
    private LandingFooter $footer;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();

        // Built by hand, as read models are in these tests; the threshold is the container's.
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        $minVenues = self::getContainer()->getParameter('landing.min_venues');
        self::assertIsInt($minVenues);
        $this->pool = new ArrayAdapter();
        $this->footer = new LandingFooter($this->pool);
        $this->pages = new LandingPages($this->connection, ListingQueryTest::enabledLocale(), $translator, $this->footer, $minVenues);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_the_threshold_is_five_in_test(): void
    {
        self::assertSame(5, self::getContainer()->getParameter('landing.min_venues'));
    }

    public function test_a_canton_page_switches_on_at_the_fifth_venue(): void
    {
        $this->inCanton(Canton::TI, 4);
        self::assertNull($this->pages->canton(Canton::TI));
        self::assertNull($this->pages->bySlug('tessin'));

        $this->inCanton(Canton::TI, 1);
        $this->pages->reset();

        $page = $this->pages->canton(Canton::TI);
        self::assertNotNull($page);
        self::assertSame(LandingType::Canton, $page->type);
        self::assertSame('tessin', $page->slug);
        self::assertSame('tessin', $page->cantonSlug);
        self::assertSame(5, $page->venues);
        self::assertSame($page, $this->pages->bySlug('tessin'));
        self::assertSame(['canton' => 'ti'], $page->facetQuery());
        self::assertSame(['canton'], $page->consumes());
    }

    public function test_unpublished_and_untranslated_venues_do_not_count(): void
    {
        $this->inCanton(Canton::BE, 4);
        $this->inCanton(Canton::BE, 3, VenueStatus::Unpublished);
        VenueFactory::new()->withoutLocation(repository(CantonRow::class)->find('be'))->create(); // no translation: not published

        self::assertNull($this->pages->canton(Canton::BE));
    }

    public function test_cantons_are_ordered_by_count_then_code(): void
    {
        $this->inCanton(Canton::GE, 5);
        $this->inCanton(Canton::BE, 5);
        $this->inCanton(Canton::ZH, 6);

        self::assertSame(['zh', 'be', 'ge'], array_map(static fn (LandingPage $page): ?string => $page->canton?->value, $this->pages->cantons()));
    }

    public function test_venues_without_a_canton_are_on_no_canton_page(): void
    {
        $this->inCanton(Canton::ZH, 5);
        DetailVenues::withoutPlace();
        DetailVenues::inRegionOnly($this->em, 'Schweizweit');

        self::assertSame([5], array_map(static fn (LandingPage $page): int => $page->venues, $this->pages->cantons()));
    }

    public function test_a_town_page_needs_five_venues_in_one_town(): void
    {
        $this->inTown('Stäfa', 4);
        $this->inTown('Zürich', 1);
        self::assertSame([], $this->pages->towns());

        $this->inTown('Stäfa', 1);
        $this->pages->reset();

        $town = $this->town('Stäfa');
        $page = $this->pages->town($town->id());
        self::assertNotNull($page);
        self::assertSame(LandingType::Town, $page->type);
        self::assertSame('staefa', $page->slug);
        self::assertSame('zuerich', $page->cantonSlug);
        self::assertSame('Stäfa', $page->townName);
        self::assertSame(Canton::ZH, $page->canton);
        self::assertSame($page, $this->pages->byTown('zuerich', 'staefa'));
        self::assertNull($this->pages->byTown('bern', 'staefa'));
        self::assertSame([$page], $this->pages->towns(Canton::ZH));
        self::assertSame([], $this->pages->towns(Canton::BE));
        self::assertSame(['canton' => 'zh', 'place' => $town->id(), 'radius' => '0'], $page->facetQuery());
        self::assertNotNull($this->pages->canton(Canton::ZH), 'Its canton has the same venues and more.');
    }

    public function test_a_building_page_needs_five_venues_with_the_building(): void
    {
        $this->withBuilding($this->inCanton(Canton::ZH, 4), 'castle');
        self::assertSame([], $this->pages->buildings());

        $venue = $this->withBuilding($this->inCanton(Canton::BE, 1), 'castle')[0];
        VenueTermFactory::new()->term(TermGroup::Setting, 'lake')->create(['venue' => $venue]);
        $this->pages->reset();

        $page = $this->pages->building('castle');
        self::assertNotNull($page);
        self::assertSame(LandingType::Building, $page->type);
        self::assertSame('schloss', $page->slug);
        self::assertNull($page->canton);
        self::assertSame($page, $this->pages->bySlug('schloss'));
        self::assertSame(['building' => ['castle']], $page->facetQuery());
        self::assertSame([$page], $this->pages->buildings());
    }

    public function test_an_unknown_or_thin_slug_has_no_page(): void
    {
        $this->inCanton(Canton::ZH, 5);

        self::assertNull($this->pages->bySlug('bern'));
        self::assertNull($this->pages->bySlug('schloss'));
        self::assertNull($this->pages->bySlug('uri-und-so'));
    }

    public function test_every_method_shares_one_query_and_reset_asks_again(): void
    {
        $this->inCanton(Canton::ZH, 5);
        $holder = $this->holder();

        $holder->reset();
        $this->pages->cantons();
        $this->pages->towns();
        $this->pages->buildings();
        $this->pages->bySlug('zuerich');
        $this->pages->byTown('zuerich', 'staefa');
        $this->pages->canton(Canton::ZH);
        $this->pages->building('castle');
        self::assertCount(1, $holder->getData()['default'] ?? []);

        $this->inCanton(Canton::BE, 5);
        self::assertNull($this->pages->canton(Canton::BE), 'The pages are memoized.');

        $this->pages->reset();
        self::assertNotNull($this->pages->canton(Canton::BE));
    }

    public function test_a_run_stores_the_canton_pages_for_the_footer_and_rewrites_only_on_change(): void
    {
        $footer = $this->footer;
        self::assertSame([], $footer->cantons(), 'A cold pool has no footer list.');

        $this->inCanton(Canton::BE, 6);
        $this->inCanton(Canton::ZH, 5);
        $this->pages->cantons();

        self::assertSame(
            [['canton' => Canton::BE, 'slug' => 'bern'], ['canton' => Canton::ZH, 'slug' => 'zuerich']],
            $footer->cantons(),
        );

        // An unchanged list is not written again: an expiry put on the stored item survives.
        $item = $this->pool->getItem(LandingFooter::KEY);
        $this->pool->save($item->expiresAfter(3600));
        $before = $this->pool->getItem(LandingFooter::KEY)->getMetadata();
        $this->pages->reset();
        $this->pages->cantons();
        self::assertSame($before, $this->pool->getItem(LandingFooter::KEY)->getMetadata());

        $this->inCanton(Canton::GE, 5);
        $this->pages->reset();
        $this->pages->cantons();
        self::assertCount(3, $footer->cantons());
    }

    public function test_no_published_venue_gives_no_page(): void
    {
        self::assertSame([], $this->pages->all());
    }

    private function holder(): BacktraceDebugDataHolder
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        return $holder;
    }
}
