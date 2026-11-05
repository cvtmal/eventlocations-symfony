<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\ReadModel\ContactLocationQuery;
use App\Crm\ReadModel\OwnerOptionsQuery;
use App\Crm\ReadModel\VenueLookupQuery;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\VenueFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The small lookups of the back office against Postgres: the owner options, the venue lookup and a contact row's
 * Location (the redirect of a former contact page, #55).
 */
final class LookupQueriesTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

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

    public function test_a_contact_row_names_its_location(): void
    {
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => $company]);
        $query = new ContactLocationQuery($this->connection);

        self::assertSame($company->id(), $query->companyIdOf($contact->id()));
        self::assertNull($query->companyIdOf(EntityId::generate()));
        self::assertNull($query->companyIdOf('not-a-uuid'));
    }
}
