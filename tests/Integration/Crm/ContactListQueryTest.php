<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\ReadModel\CompanyListFilter;
use App\Crm\ReadModel\ContactListFilter;
use App\Crm\ReadModel\ContactListQuery;
use App\Crm\ReadModel\ContactListRow;
use App\Crm\ReadModel\ContactRecordQuery;
use App\Crm\ReadModel\ContactSort;
use App\Crm\ReadModel\OwnerOptionsQuery;
use App\Crm\ReadModel\SortDirection;
use App\Crm\ReadModel\VenueLookupQuery;
use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\ContactKind;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\VenueFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The contact list's and record's statements, the owner options and the venue lookup against Postgres.
 */
final class ContactListQueryTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_it_reads_a_row_with_company_and_owner(): void
    {
        $owner = AdminUserFactory::createOne(['displayName' => 'Andrea Keller']);
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events']);
        $contact = ContactFactory::createOne(['firstName' => 'Laura', 'lastName' => 'Meier', 'kind' => ContactKind::VenueContact, 'company' => $company, 'email' => 'laura@example.ch', 'owner' => $owner]);

        $row = $this->query()->search(new ContactListFilter(), ContactSort::Name, SortDirection::Asc, 1)->rows[0];

        self::assertSame([$contact->id(), 'Laura Meier', ContactKind::VenueContact, $company->id(), 'Seeblick Events', 'laura@example.ch', 'Andrea Keller'], [$row->id, $row->fullName(), $row->kind, $row->companyId, $row->companyName, $row->email, $row->ownerName]);
        self::assertSame('LM', $row->initials());
    }

    public function test_the_filters_combine(): void
    {
        $owner = AdminUserFactory::createOne();
        ContactFactory::createOne(['firstName' => 'Jürg', 'lastName' => 'Müller', 'kind' => ContactKind::Couple, 'email' => 'j_m@example.ch', 'owner' => $owner]);
        ContactFactory::createOne(['firstName' => 'Anna', 'lastName' => 'Müller', 'kind' => ContactKind::Other, 'email' => 'jxm@example.ch']);
        $query = $this->query();

        self::assertSame(['Anna Müller', 'Jürg Müller'], $this->names($query, new ContactListFilter(q: 'mueller')));
        self::assertSame(['Jürg Müller'], $this->names($query, new ContactListFilter(q: 'j_m@')), 'An underscore is a character, not a wildcard.');
        self::assertSame(['Jürg Müller'], $this->names($query, new ContactListFilter(kind: ContactKind::Couple)));
        self::assertSame(['Jürg Müller'], $this->names($query, new ContactListFilter(owner: $owner->id())));
        self::assertSame(['Anna Müller'], $this->names($query, new ContactListFilter(q: 'müller', owner: CompanyListFilter::OWNER_NONE)));
    }

    public function test_the_record_carries_company_and_owner(): void
    {
        $owner = AdminUserFactory::createOne(['displayName' => 'Andrea Keller']);
        $company = CompanyFactory::new()->inStage(CompanyStage::FormerCustomer)->create(['name' => 'Seeblick Events']);
        $contact = ContactFactory::createOne(['company' => $company, 'owner' => $owner]);
        $records = self::getContainer()->get(ContactRecordQuery::class);
        self::assertInstanceOf(ContactRecordQuery::class, $records);

        $record = $records->find($contact->id());

        self::assertNotNull($record);
        self::assertSame(['Seeblick Events', CompanyStage::FormerCustomer, 'SE', $owner->id(), 'Andrea Keller'], [$record->companyName, $record->companyStage, $record->companyInitials(), $record->ownerId, $record->ownerName]);
        self::assertNull($records->find(EntityId::generate()));
        self::assertNull($records->find('1'));
    }

    public function test_owner_options_list_every_account_and_keep_a_deactivated_current_one(): void
    {
        $active = AdminUserFactory::createOne(['displayName' => 'Andrea Keller']);
        $former = AdminUserFactory::createOne(['displayName' => 'Bea Ehemalig']);
        $former->deactivate();
        $this->em->flush();
        $owners = self::getContainer()->get(OwnerOptionsQuery::class);
        self::assertInstanceOf(OwnerOptionsQuery::class, $owners);

        $all = $owners->all();

        self::assertSame([$active->id(), $former->id()], array_map(static fn ($owner): string => $owner->id, $all));
        self::assertSame([$active->id()], array_map(static fn ($owner): string => $owner->id, OwnerOptionsQuery::selectable($all)));
        self::assertSame([$active->id(), $former->id()], array_map(static fn ($owner): string => $owner->id, OwnerOptionsQuery::selectable($all, $former->id())));
    }

    public function test_the_venue_lookup_finds_by_name_or_number(): void
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);
        CompanyFactory::new()->withVenues($venue)->create(['name' => 'Seeblick Events']);
        $lookup = self::getContainer()->get(VenueLookupQuery::class);
        self::assertInstanceOf(VenueLookupQuery::class, $lookup);

        self::assertSame([[$venue->id(), 'Seeblick Events']], array_map(static fn ($option): array => [$option->id, $option->companyName], $lookup->search('seeblick', 10)));
        self::assertSame([$venue->id()], array_map(static fn ($option): string => $option->id, $lookup->search((string) $venue->number(), 10)));
        self::assertSame([], $lookup->search('1234567890', 10));
        self::assertSame([], $lookup->search(' ', 10));
    }

    /**
     * @return list<string>
     */
    private function names(ContactListQuery $query, ContactListFilter $filter): array
    {
        return array_map(static fn (ContactListRow $row): string => $row->fullName(), $query->search($filter, ContactSort::Name, SortDirection::Asc, 1)->rows);
    }

    private function query(): ContactListQuery
    {
        $query = self::getContainer()->get(ContactListQuery::class);
        self::assertInstanceOf(ContactListQuery::class, $query);

        return $query;
    }
}
