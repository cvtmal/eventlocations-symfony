<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\ContactSheet;
use App\Crm\ContactSheetRow;
use App\Crm\ValueObject\ContactBasis;
use App\Crm\ValueObject\ContactChannel;
use App\Crm\ValueObject\Outreach;
use App\Crm\ValueObject\OutreachCohort;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The contact sheet (F14): the fictional template reads, a good row passes the CRM's field and outreach rules, a bad
 * one gets one reason code per problem and no value, and the venue column takes a number or a content key.
 */
final class ContactSheetTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    private const string FIXTURE = __DIR__.'/../../Fixtures/Onboarding/contacts.csv';

    public function test_the_template_reads_and_each_row_is_checked(): void
    {
        $rows = ContactSheet::read(self::FIXTURE);
        $sheet = $this->sheet();

        self::assertSame([2, 3, 4, 5], array_map(static fn (ContactSheetRow $row): int => $row->line, $rows));
        self::assertSame(['errors' => [], 'venueNumber' => null], $sheet->check($rows[0]));
        self::assertSame(['errors' => [], 'venueNumber' => null], $sheet->check($rows[1]));
        self::assertSame(['errors' => [], 'venueNumber' => null], $sheet->check($rows[2]));
        self::assertSame([
            'row 5: email: crm.property.email',
            'row 5: last_name: crm.property.required',
            'row 5: cohort: unknown',
            'row 5: channel: unknown',
        ], $sheet->check($rows[3])['errors']);

        self::assertEquals(new Outreach('brief-2026-11', OutreachCohort::A, ContactChannel::Postal, ContactBasis::OneToOne), $rows[0]->outreach());
        self::assertSame(['info@seehof-muster.example', 'lena@seehof-muster.example'], $rows[0]->emails());
        self::assertTrue($rows[0]->hasContact());
        self::assertFalse($rows[1]->hasContact());
        $command = $rows[1]->toCommand('company-id', 'contact-id', null);
        self::assertNull($command->contactId);
        self::assertSame(['Halle Erfunden GmbH', 'Industriestrasse 5, 4051 Basel'], [$command->name, $command->address]);
    }

    public function test_a_campaign_goes_with_letters_only_and_the_venue_is_found_by_number_or_key(): void
    {
        $venue = VenueFactory::createOne(['contentKey' => 'seehof-muster']);
        $row = static fn (array $cells): ContactSheetRow => new ContactSheetRow(9, $cells + ['location' => 'Seehof Muster AG']);

        self::assertSame(['row 9: campaign: crm.outreach.campaign_not_postal'], $this->sheet()->check($row(['channel' => 'email', 'campaign' => 'brief-2026-11']))['errors']);
        self::assertSame(['row 9: campaign: crm.outreach.campaign_code'], $this->sheet()->check($row(['campaign' => 'kein code!']))['errors']);
        self::assertSame(['errors' => [], 'venueNumber' => $venue->number()], $this->sheet()->check($row(['venue' => 'seehof-muster'])));
        self::assertSame(['errors' => [], 'venueNumber' => $venue->number()], $this->sheet()->check($row(['venue' => (string) $venue->number()])));
        self::assertSame(['row 9: venue: unknown'], $this->sheet()->check($row(['venue' => 'gibt-es-nicht']))['errors']);
    }

    private function sheet(): ContactSheet
    {
        $sheet = self::getContainer()->get(ContactSheet::class);
        self::assertInstanceOf(ContactSheet::class, $sheet);

        return $sheet;
    }
}
