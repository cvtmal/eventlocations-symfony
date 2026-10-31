<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\ReadModel\RecordPropertyQuery;
use App\Crm\ValueObject\CompanyProperty;
use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\ContactKind;
use App\Crm\ValueObject\ContactProperty;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The current value the API compares before it dispatches (P1): canonical, ids for references,
 * codes for enums, null for an empty field, and no record for an unknown or malformed id.
 */
final class RecordPropertyQueryTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_it_reads_every_company_field(): void
    {
        $owner = AdminUserFactory::createOne();
        $company = CompanyFactory::new()->withDetails()->inStage(CompanyStage::Customer)->create(['name' => 'Seeblick Events', 'owner' => $owner]);
        $query = $this->query();

        self::assertSame('Seeblick Events', $query->company($company->id(), CompanyProperty::Name)?->value);
        self::assertSame($company->website(), $query->company($company->id(), CompanyProperty::Website)?->value);
        self::assertSame('customer', $query->company($company->id(), CompanyProperty::Stage)?->value);
        self::assertSame($owner->id(), $query->company($company->id(), CompanyProperty::Owner)?->value);
        $notes = $query->company($company->id(), CompanyProperty::Notes);
        self::assertNotNull($notes);
        self::assertNull($notes->value);
        foreach (CompanyProperty::cases() as $property) {
            self::assertNotNull($query->company($company->id(), $property), $property->value);
        }
    }

    public function test_it_reads_every_contact_field(): void
    {
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['email' => 'anna@example.ch', 'kind' => ContactKind::VenueContact, 'company' => $company]);
        $query = $this->query();

        self::assertSame('anna@example.ch', $query->contact($contact->id(), ContactProperty::Email)?->value);
        self::assertSame('venue_contact', $query->contact($contact->id(), ContactProperty::Kind)?->value);
        self::assertSame($company->id(), $query->contact($contact->id(), ContactProperty::Company)?->value);
        foreach (ContactProperty::cases() as $property) {
            self::assertNotNull($query->contact($contact->id(), $property), $property->value);
        }
    }

    public function test_an_unknown_or_malformed_id_is_no_record(): void
    {
        $query = $this->query();

        self::assertNull($query->company(EntityId::generate(), CompanyProperty::Name));
        self::assertNull($query->company('42', CompanyProperty::Name));
        self::assertNull($query->contact(EntityId::generate(), ContactProperty::LastName));
        self::assertNull($query->contact("x'; --", ContactProperty::LastName));
    }

    private function query(): RecordPropertyQuery
    {
        $query = self::getContainer()->get(RecordPropertyQuery::class);
        self::assertInstanceOf(RecordPropertyQuery::class, $query);

        return $query;
    }
}
