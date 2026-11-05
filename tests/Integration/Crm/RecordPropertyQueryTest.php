<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\ReadModel\RecordPropertyQuery;
use App\Crm\ValueObject\CompanyProperty;
use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\DealProperty;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\ValueObject\Tier;
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

    public function test_it_reads_a_deal_field_in_its_stored_form(): void
    {
        $deal = DealFactory::createOne(['name' => 'Premium 2027', 'amountRappen' => 125050, 'tier' => Tier::Premium, 'expectedCloseOn' => new \DateTimeImmutable('2027-03-01')]);
        $query = $this->query();

        self::assertSame('Premium 2027', $query->deal($deal->id(), DealProperty::Name)?->value);
        self::assertSame('125050', $query->deal($deal->id(), DealProperty::Amount)?->value);
        self::assertSame('premium', $query->deal($deal->id(), DealProperty::Tier)?->value);
        self::assertSame('2027-03-01', $query->deal($deal->id(), DealProperty::ExpectedClose)?->value);
        foreach (DealProperty::cases() as $property) {
            self::assertNotNull($query->deal($deal->id(), $property), $property->value);
        }
        self::assertNull($query->deal(EntityId::generate(), DealProperty::Name));
    }

    public function test_an_unknown_or_malformed_id_is_no_record(): void
    {
        $query = $this->query();

        self::assertNull($query->company(EntityId::generate(), CompanyProperty::Name));
        self::assertNull($query->company('42', CompanyProperty::Name));
    }

    private function query(): RecordPropertyQuery
    {
        $query = self::getContainer()->get(RecordPropertyQuery::class);
        self::assertInstanceOf(RecordPropertyQuery::class, $query);

        return $query;
    }
}
