<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\ImportLocation;
use App\Crm\Exception\LocationSuppressed;
use App\Crm\Exception\VenueAlreadyLinked;
use App\Crm\ValueObject\ContactChannel;
use App\Crm\ValueObject\Outreach;
use App\Crm\ValueObject\OutreachCohort;
use App\Factory\CompanyFactory;
use App\Factory\VenueFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * F14, D49: one row of the contact list becomes a Location with its address, contact fields, outreach fields, one
 * contact and its venue; an address on the suppression list refuses the row with nothing written; a venue another
 * Location has is refused.
 */
final class ImportLocationTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_every_part_of_the_row_is_written(): void
    {
        $venue = VenueFactory::createOne();
        $companyId = EntityId::generate();

        $this->dispatch($this->command($companyId, $venue->number()));

        self::assertSame(
            ['name' => 'Seehof Muster AG', 'origin' => 'manual', 'address' => 'Seestrasse 10, 8712 Stäfa', 'email' => 'info@seehof-muster.example', 'phone' => '044 555 01 01', 'website' => 'https://seehof-muster.example', 'cohort' => 'A', 'contact_channel' => 'postal', 'campaign_code' => 'brief-2026-11', 'venue_id' => $venue->id()],
            $this->connection->fetchAssociative('SELECT name, origin, address, email, phone, website, cohort, contact_channel, campaign_code, venue_id FROM crm_companies WHERE id = ?', [$companyId]),
        );
        self::assertSame(
            [['first_name' => 'Lena', 'last_name' => 'Beispiel', 'email' => 'lena@seehof-muster.example', 'phone' => '079 555 01 02', 'position' => 'Geschäftsführerin']],
            $this->connection->fetchAllAssociative('SELECT first_name, last_name, email, phone, position FROM crm_contacts WHERE company_id = ?', [$companyId]),
        );
        self::assertEquals([['command' => ImportLocation::class, 'changes' => ['import' => true, 'contact' => true, 'venue' => true]]], $this->auditRows('company', $companyId));
    }

    public function test_a_suppressed_address_refuses_the_row(): void
    {
        $this->connection->insert('suppressions', ['id' => EntityId::generate(), 'email' => 'lena@seehof-muster.example', 'reason' => 'opt_out']);
        $companyId = EntityId::generate();

        $this->failure($this->command($companyId, null), LocationSuppressed::class);

        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_companies WHERE id = ?', $companyId));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_contacts WHERE email = ?', 'lena@seehof-muster.example'));
    }

    public function test_a_venue_another_location_has_is_refused(): void
    {
        $venue = VenueFactory::createOne();
        CompanyFactory::new()->withVenue($venue)->create();

        $this->failure($this->command(EntityId::generate(), $venue->number()), VenueAlreadyLinked::class);
    }

    private function command(string $companyId, ?int $venueNumber): ImportLocation
    {
        return new ImportLocation(
            $companyId, 'Seehof Muster AG', 'Seestrasse 10, 8712 Stäfa', 'Info@Seehof-Muster.example', '044 555 01 01', 'https://seehof-muster.example',
            EntityId::generate(), 'Lena', 'Beispiel', 'Geschäftsführerin', 'lena@seehof-muster.example', '079 555 01 02',
            new Outreach('brief-2026-11', OutreachCohort::A, ContactChannel::Postal), $venueNumber,
        );
    }
}
