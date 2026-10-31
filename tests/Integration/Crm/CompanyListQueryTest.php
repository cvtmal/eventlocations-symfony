<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\ReadModel\CompanyListFilter;
use App\Crm\ReadModel\CompanyListQuery;
use App\Crm\ReadModel\CompanyListRow;
use App\Crm\ReadModel\CompanyRecordQuery;
use App\Crm\ReadModel\CompanySort;
use App\Crm\ReadModel\SortDirection;
use App\Crm\ValueObject\CompanyStage;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\VenueFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The company list's and record's statements against Postgres (D20).
 */
final class CompanyListQueryTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_it_reads_a_row_with_owner_and_venue_count(): void
    {
        $owner = AdminUserFactory::createOne(['displayName' => 'Andrea Keller']);
        $venues = VenueFactory::new()->withoutLocation(null)->many(2)->create();
        $company = CompanyFactory::new()->withDetails()->inStage(CompanyStage::Customer)->withVenues(...$venues)->create(['name' => 'Seeblick Events GmbH', 'owner' => $owner]);

        $page = $this->query()->search(new CompanyListFilter(), CompanySort::Name, SortDirection::Asc, 1);

        self::assertSame(1, $page->total);
        $row = $page->rows[0];
        self::assertSame([$company->id(), 'Seeblick Events GmbH', $company->website(), CompanyStage::Customer, 'Andrea Keller', 2], [$row->id, $row->name, $row->website, $row->stage, $row->ownerName, $row->venueCount]);
        self::assertSame('SE', $row->initials());
    }

    public function test_the_filters_combine(): void
    {
        $owner = AdminUserFactory::createOne();
        CompanyFactory::new()->inStage(CompanyStage::Customer)->withVenues(VenueFactory::new()->withoutLocation(null)->create())->create(['name' => 'Zürcher Gastro', 'owner' => $owner]);
        CompanyFactory::new()->inStage(CompanyStage::Customer)->create(['name' => 'Zürcher Hof']);
        CompanyFactory::createOne(['name' => 'Alpenhof']);
        $query = $this->query();

        self::assertSame(['Zürcher Gastro', 'Zürcher Hof'], $this->names($query, new CompanyListFilter(q: 'zuercher')));
        self::assertSame(['Zürcher Gastro', 'Zürcher Hof'], $this->names($query, new CompanyListFilter(q: 'ZÜRCHER')));
        self::assertSame([], $this->names($query, new CompanyListFilter(q: '!!!')));
        self::assertSame(['Zürcher Gastro'], $this->names($query, new CompanyListFilter(stage: CompanyStage::Customer, owner: $owner->id())));
        self::assertSame(['Alpenhof', 'Zürcher Hof'], $this->names($query, new CompanyListFilter(owner: CompanyListFilter::OWNER_NONE)));
        self::assertSame(['Zürcher Hof'], $this->names($query, new CompanyListFilter(q: 'zürcher', venues: CompanyListFilter::VENUES_WITHOUT)));
    }

    public function test_it_sorts_and_clamps_the_page(): void
    {
        foreach (['b', 'A', 'c'] as $name) {
            CompanyFactory::createOne(['name' => $name]);
        }
        $query = $this->query();

        self::assertSame(['A', 'b', 'c'], array_map(static fn (CompanyListRow $row): string => $row->name, $query->search(new CompanyListFilter(), CompanySort::Name, SortDirection::Asc, 1)->rows));
        self::assertSame(['c', 'b', 'A'], array_map(static fn (CompanyListRow $row): string => $row->name, $query->search(new CompanyListFilter(), CompanySort::Name, SortDirection::Desc, 1)->rows));
        $page = $query->search(new CompanyListFilter(), CompanySort::Created, SortDirection::Asc, \PHP_INT_MAX);
        self::assertSame([1, 1, 3], [$page->page, $page->lastPage, \count($page->rows)]);
    }

    public function test_the_quick_search_finds_by_name_only(): void
    {
        CompanyFactory::createOne(['name' => 'Seeblick Events']);

        self::assertSame(['Seeblick Events'], array_map(static fn (CompanyListRow $row): string => $row->name, $this->query()->quickSearch('seeblick', 5)));
        self::assertSame([], $this->query()->quickSearch('  ', 5));
    }

    public function test_the_record_carries_venues_and_contacts(): void
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);
        $company = CompanyFactory::new()->withVenues($venue)->create(['name' => 'Seeblick Events']);
        ContactFactory::createOne(['firstName' => 'Marc', 'lastName' => 'Widmer', 'company' => $company]);
        ContactFactory::createOne(['firstName' => null, 'lastName' => 'Brunner', 'company' => $company]);
        $records = self::getContainer()->get(CompanyRecordQuery::class);
        self::assertInstanceOf(CompanyRecordQuery::class, $records);

        $record = $records->find($company->id());

        self::assertNotNull($record);
        self::assertSame([[$venue->id(), $venue->number(), 'Schloss Seeblick']], array_map(static fn ($v): array => [$v->id, $v->number, $v->name], $record->venues));
        self::assertFalse($record->venues[0]->place->isKnown());
        self::assertSame(['Brunner', 'Marc Widmer'], array_map(static fn ($c): string => $c->fullName(), $record->contacts));
        self::assertSame('Seeblick Events', $records->name($company->id()));
        self::assertNull($records->find(EntityId::generate()));
        self::assertNull($records->find('not-a-uuid'));
        self::assertNull($records->name('not-a-uuid'));
    }

    /**
     * @return list<string>
     */
    private function names(CompanyListQuery $query, CompanyListFilter $filter): array
    {
        return array_map(static fn (CompanyListRow $row): string => $row->name, $query->search($filter, CompanySort::Name, SortDirection::Asc, 1)->rows);
    }

    private function query(): CompanyListQuery
    {
        $query = self::getContainer()->get(CompanyListQuery::class);
        self::assertInstanceOf(CompanyListQuery::class, $query);

        return $query;
    }
}
