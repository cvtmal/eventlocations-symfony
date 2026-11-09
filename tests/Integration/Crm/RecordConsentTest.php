<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\RecordConsent;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\ValueObject\ContactChannel;
use App\Crm\ValueObject\Outreach;
use App\Crm\ValueObject\OutreachCohort;
use App\Factory\CompanyFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * F14, D29: a hochzeitslocation.ch customer's yes is a note on the Location that nobody can edit (no actor), dated the
 * day of the yes and naming the address; the basis of contact becomes "consent" and the other outreach fields stay.
 * The same yes twice writes one entry.
 */
final class RecordConsentTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_the_yes_lands_on_the_timeline_once_and_sets_the_basis(): void
    {
        $company = CompanyFactory::new()->withOutreach(new Outreach('brief-2026-11', OutreachCohort::B, ContactChannel::Postal))->create(['name' => 'Gutshof Erfunden']);

        self::assertTrue($this->dispatch(new RecordConsent($company->id(), 'Info@Gutshof-Erfunden.example', '2026-10-12')));

        $activity = $this->connection->fetchAssociative(
            "SELECT a.type_code, a.actor_id, a.title, a.body, to_char(a.occurred_at AT TIME ZONE 'Europe/Zurich', 'YYYY-MM-DD HH24:MI') AS occurred, a.payload::text AS payload
             FROM crm_activities a JOIN crm_activity_companies l ON l.activity_id = a.id WHERE l.company_id = ?",
            [$company->id()],
        );
        self::assertIsArray($activity);
        self::assertSame([
            'type_code' => 'note',
            'actor_id' => null,
            'title' => null,
            'body' => 'Zustimmung zum Import von hochzeitslocation.ch: «Ja» von info@gutshof-erfunden.example am 12.10.2026.',
            'occurred' => '2026-10-12 12:00',
        ], \array_slice($activity, 0, 5));
        self::assertSame(['consent' => ['on' => '2026-10-12', 'from' => 'info@gutshof-erfunden.example', 'source' => 'hochzeitslocation']], json_decode((string) $activity['payload'], true));
        self::assertSame(
            ['contact_basis' => 'consent', 'campaign_code' => 'brief-2026-11', 'cohort' => 'B', 'contact_channel' => 'postal'],
            $this->connection->fetchAssociative('SELECT contact_basis, campaign_code, cohort, contact_channel FROM crm_companies WHERE id = ?', [$company->id()]),
        );

        self::assertFalse($this->dispatch(new RecordConsent($company->id(), 'info@gutshof-erfunden.example', '2026-10-12')));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_activity_companies WHERE company_id = ?', $company->id()));
        self::assertSame(
            [['command' => RecordConsent::class, 'changes' => ['consent' => 'recorded']], ['command' => RecordConsent::class, 'changes' => ['consent' => 'recorded']]],
            $this->auditRows('company', $company->id()),
        );
    }

    public function test_a_day_that_is_no_date_is_refused(): void
    {
        $company = CompanyFactory::createOne();

        $this->failure(new RecordConsent($company->id(), 'info@gutshof-erfunden.example', '12.10.2026'), InvalidPropertyValue::class);
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_activity_companies WHERE company_id = ?', $company->id()));
    }
}
