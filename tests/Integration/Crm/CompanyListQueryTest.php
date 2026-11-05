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

    public function test_it_reads_a_row_with_owner_and_eintrag(): void
    {
        $owner = AdminUserFactory::createOne(['displayName' => 'Andrea Keller']);
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);
        $company = CompanyFactory::new()->withDetails()->inStage(CompanyStage::Customer)->withVenue($venue)->create(['name' => 'Seeblick Events GmbH', 'owner' => $owner]);
        CompanyFactory::createOne(['name' => 'Zürcher Hof']);

        $page = $this->query()->search(new CompanyListFilter(), CompanySort::Name, SortDirection::Asc, 1);

        self::assertSame(2, $page->total);
        $row = $page->rows[0];
        self::assertSame([$company->id(), 'Seeblick Events GmbH', $company->website(), CompanyStage::Customer, 'Andrea Keller', 'Schloss Seeblick'], [$row->id, $row->name, $row->website, $row->stage, $row->ownerName, $row->venueName]);
        self::assertSame('SE', $row->initials());
        self::assertNull($page->rows[1]->venueName);
    }

    public function test_the_filters_combine(): void
    {
        $owner = AdminUserFactory::createOne();
        CompanyFactory::new()->inStage(CompanyStage::Customer)->withVenue(VenueFactory::new()->withoutLocation(null)->create())->create(['name' => 'Zürcher Gastro', 'owner' => $owner]);
        CompanyFactory::new()->inStage(CompanyStage::Customer)->create(['name' => 'Zürcher Hof']);
        CompanyFactory::createOne(['name' => 'Alpenhof']);
        $query = $this->query();

        self::assertSame(['Zürcher Gastro', 'Zürcher Hof'], $this->names($query, new CompanyListFilter(q: 'zuercher')));
        self::assertSame(['Zürcher Gastro', 'Zürcher Hof'], $this->names($query, new CompanyListFilter(q: 'ZÜRCHER')));
        self::assertSame([], $this->names($query, new CompanyListFilter(q: '!!!')));
        self::assertSame(['Zürcher Gastro'], $this->names($query, new CompanyListFilter(stage: CompanyStage::Customer, owner: $owner->id())));
        self::assertSame(['Alpenhof', 'Zürcher Hof'], $this->names($query, new CompanyListFilter(owner: CompanyListFilter::OWNER_NONE)));
        self::assertSame(['Zürcher Hof'], $this->names($query, new CompanyListFilter(q: 'zürcher', venues: CompanyListFilter::VENUES_WITHOUT)));
        self::assertSame(['Zürcher Gastro'], $this->names($query, new CompanyListFilter(venues: CompanyListFilter::VENUES_WITH)));
    }

    public function test_sorting_by_eintrag_orders_by_its_name_with_the_ones_without_last(): void
    {
        CompanyFactory::new()->withVenue(VenueFactory::new()->withoutLocation(null)->create(['name' => 'Bergsicht']))->create(['name' => 'Zeta']);
        CompanyFactory::new()->withVenue(VenueFactory::new()->withoutLocation(null)->create(['name' => 'Alpenblick']))->create(['name' => 'Ypsilon']);
        CompanyFactory::createOne(['name' => 'Alpha']);
        $query = $this->query();
        $names = static fn (SortDirection $direction): array => array_map(static fn (CompanyListRow $row): string => $row->name, $query->search(new CompanyListFilter(), CompanySort::Venues, $direction, 1)->rows);

        self::assertSame(['Ypsilon', 'Zeta', 'Alpha'], $names(SortDirection::Asc));
        self::assertSame(['Zeta', 'Ypsilon', 'Alpha'], $names(SortDirection::Desc));
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

    public function test_a_location_is_found_by_its_contact_s_name_or_email(): void
    {
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events']);
        ContactFactory::createOne(['firstName' => 'Jürg', 'lastName' => 'Müller', 'company' => $company, 'email' => 'juerg@seeblick.example']);
        ContactFactory::createOne(['firstName' => 'Jürg', 'lastName' => 'Müller', 'company' => $company, 'email' => 'zweiter@seeblick.example']);
        CompanyFactory::createOne(['name' => 'Alpenhof AG']);
        $query = $this->query();

        foreach (['muller', 'Jürg Müller', 'JUERG@seeblick', 'seeblick.example'] as $q) {
            self::assertSame(['Seeblick Events'], array_map(static fn (CompanyListRow $row): string => $row->name, $query->quickSearch($q, 5)), $q);
            self::assertSame(['Seeblick Events'], $this->names($query, new CompanyListFilter(q: $q)), $q);
        }
        self::assertSame([], $query->quickSearch('%', 5));
        self::assertSame([], $query->quickSearch('!!!', 5));
    }

    public function test_the_record_carries_its_eintrag_and_contacts(): void
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);
        $company = CompanyFactory::new()->withVenue($venue)->create(['name' => 'Seeblick Events']);
        ContactFactory::new()->withDetails()->create(['firstName' => 'Marc', 'lastName' => 'Widmer', 'company' => $company]);
        ContactFactory::createOne(['firstName' => null, 'lastName' => 'Brunner', 'company' => $company]);
        $records = self::getContainer()->get(CompanyRecordQuery::class);
        self::assertInstanceOf(CompanyRecordQuery::class, $records);

        $record = $records->find($company->id());

        self::assertNotNull($record);
        self::assertNotNull($record->venue);
        self::assertSame([$venue->id(), $venue->number(), 'Schloss Seeblick'], [$record->venue->id, $record->venue->number, $record->venue->name]);
        self::assertFalse($record->venue->place->isKnown());
        self::assertNull($records->find(CompanyFactory::createOne()->id())?->venue);
        self::assertSame(['Brunner', 'Marc Widmer'], array_map(static fn ($c): string => $c->fullName(), $record->contacts));
        self::assertSame([null, true], [$record->contacts[0]->phone, null !== $record->contacts[1]->phone]);
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
