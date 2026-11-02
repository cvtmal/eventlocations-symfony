<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\ReadModel\EmailMergeQuery;
use App\Crm\ValueObject\MergeFields;
use App\Crm\ValueObject\RecordKind;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class EmailMergeQueryTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    private const string ALL = '{contact.first_name}|{company.name}|{venue.name}';

    public function test_a_company_without_a_venue_leaves_the_venue_empty(): void
    {
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events GmbH']);

        self::assertSame('|Seeblick Events GmbH|', $this->merged(RecordKind::Company, $company->id(), null));
    }

    public function test_a_company_with_one_venue_names_it_with_the_chosen_contact(): void
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);
        $company = CompanyFactory::new()->withVenues($venue)->create(['name' => 'Seeblick Events GmbH']);
        $contact = ContactFactory::createOne(['firstName' => 'Laura', 'company' => $company]);

        self::assertSame('|Seeblick Events GmbH|Schloss Seeblick', $this->merged(RecordKind::Company, $company->id(), null));
        self::assertSame('Laura|Seeblick Events GmbH|Schloss Seeblick', $this->merged(RecordKind::Company, $company->id(), $contact->id()));
    }

    public function test_a_company_with_two_venues_leaves_the_venue_empty(): void
    {
        $company = CompanyFactory::new()->withVenues(
            VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']),
            VenueFactory::new()->withoutLocation(null)->create(['name' => 'Gut Rosenberg']),
        )->create(['name' => 'Seeblick Events GmbH']);

        self::assertSame('|Seeblick Events GmbH|', $this->merged(RecordKind::Company, $company->id(), null));
    }

    public function test_a_contact_brings_its_company_and_venue(): void
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);
        $company = CompanyFactory::new()->withVenues($venue)->create(['name' => 'Seeblick Events GmbH']);
        $contact = ContactFactory::createOne(['firstName' => 'Laura', 'company' => $company]);

        self::assertSame('Laura|Seeblick Events GmbH|Schloss Seeblick', $this->merged(RecordKind::Contact, $contact->id(), null));
    }

    public function test_a_contact_without_a_company_leaves_both_empty(): void
    {
        $contact = ContactFactory::createOne(['firstName' => 'Laura', 'company' => null]);

        self::assertSame('Laura||', $this->merged(RecordKind::Contact, $contact->id(), null));
    }

    public function test_a_contact_of_another_company_gives_null(): void
    {
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => CompanyFactory::createOne()]);

        self::assertNull($this->fields(RecordKind::Company, $company->id(), $contact->id()));
    }

    private function merged(RecordKind $kind, string $recordId, ?string $contactId): string
    {
        $fields = $this->fields($kind, $recordId, $contactId);
        self::assertNotNull($fields);

        return $fields->apply(self::ALL);
    }

    private function fields(RecordKind $kind, string $recordId, ?string $contactId): ?MergeFields
    {
        return new EmailMergeQuery($this->connection)->fields($kind, $recordId, $contactId);
    }
}
