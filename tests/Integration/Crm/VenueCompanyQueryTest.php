<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\ReadModel\CompanyRecordContact;
use App\Crm\ReadModel\VenueCompanyQuery;
use App\Crm\ValueObject\CompanyStage;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The venue panel's read (D2, D20): one statement through `v.number`, with or without a company.
 */
final class VenueCompanyQueryTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_a_venue_with_a_company_gives_it_with_its_contacts_in_one_statement(): void
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create();
        $company = CompanyFactory::new()->inStage(CompanyStage::Customer)->withVenues($venue)->create(['name' => 'Seeblick Events GmbH']);
        ContactFactory::createOne(['firstName' => 'Laura', 'lastName' => 'Meier', 'company' => $company]);
        ContactFactory::createOne(['firstName' => null, 'lastName' => 'Aebi', 'company' => $company]);
        ContactFactory::createOne(['lastName' => 'Anderswo']);
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);
        $holder->reset();

        $found = $this->query()->forVenue($venue->number());

        self::assertCount(1, $holder->getData()['default'] ?? []);
        self::assertNotNull($found);
        self::assertSame($venue->id(), $found->venueId);
        self::assertSame($company->id(), $found->companyId);
        self::assertSame('Seeblick Events GmbH', $found->companyName);
        self::assertSame('SE', $found->initials());
        self::assertSame(CompanyStage::Customer, $found->stage);
        self::assertNotNull($found->since);
        self::assertSame(['Aebi', 'Laura Meier'], array_map(static fn (CompanyRecordContact $contact): string => $contact->fullName(), $found->contacts));
    }

    public function test_a_venue_without_a_company_gives_only_its_id(): void
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create();

        $found = $this->query()->forVenue($venue->number());

        self::assertNotNull($found);
        self::assertSame($venue->id(), $found->venueId);
        self::assertNull($found->companyId);
        self::assertSame([], $found->contacts);
    }

    public function test_an_unknown_number_gives_nothing(): void
    {
        self::assertNull($this->query()->forVenue(999999999));
    }

    private function query(): VenueCompanyQuery
    {
        $query = self::getContainer()->get(VenueCompanyQuery::class);
        self::assertInstanceOf(VenueCompanyQuery::class, $query);

        return $query;
    }
}
