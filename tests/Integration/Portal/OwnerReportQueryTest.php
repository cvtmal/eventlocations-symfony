<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Factory\OwnerReportFactory;
use App\Factory\PortalUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\ReportOptOutFactory;
use App\Factory\RoomFactory;
use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Portal\ReadModel\LocationPlanQuery;
use App\Portal\ReadModel\OwnerReportQuery;
use App\Portal\ReadModel\ReportOptOutState;
use App\Portal\ReadModel\VenueMailRecipients;
use App\Portal\ValueObject\ReportGaps;
use App\Portal\ValueObject\ReportMonth;
use App\Portal\ValueObject\ReportSuggestion;
use App\Shared\Translation\EnabledLocale;
use App\Stats\ReadModel\SiteStatsQuery;
use App\Stats\ReadModel\VenueMonthQuery;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\EntitlementResolver;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Which monthly reports are due and what each says (G10): published venues with an active manager, without a report
 * for the month and without an opt-out; the gaps behind the suggestion; the recipients (active managers only, D52 as
 * settled by the lead); Pro.
 */
final class OwnerReportQueryTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    private const string NOW = '2026-11-01 03:15:00 Europe/Zurich';

    public function test_due_leaves_out_unpublished_opted_out_and_sent_venues(): void
    {
        $month = self::october();
        $due = VenueFactory::new()->withoutLocation()->create();
        $this->manager($due, 'anna@example.ch', 'Anna');
        $draft = VenueFactory::new()->withoutLocation()->create(['status' => VenueStatus::Unpublished]);
        $optedOut = VenueFactory::new()->withoutLocation()->create();
        ReportOptOutFactory::createOne(['venue' => $optedOut]);
        $sent = VenueFactory::new()->withoutLocation()->create();
        OwnerReportFactory::createOne(['venue' => $sent, 'reportMonth' => $month->firstDay()]);
        // Each has a manager, so only the named reason keeps it out.
        $this->manager($draft, 'carl@example.ch', 'Carl');
        $this->manager($optedOut, 'dora@example.ch', 'Dora');
        $this->manager($sent, 'eva@example.ch', 'Eva');
        $sentBefore = VenueFactory::new()->withoutLocation()->create();
        OwnerReportFactory::createOne(['venue' => $sentBefore, 'reportMonth' => $month->previous()->firstDay()]);
        $this->manager($sentBefore, 'beat@example.ch', 'Beat');

        $ids = $this->query()->due($month);

        self::assertContains($due->id(), $ids);
        self::assertContains($sentBefore->id(), $ids, 'last month\'s report does not count for this one');
        self::assertNotContains($draft->id(), $ids);
        self::assertNotContains($optedOut->id(), $ids);
        self::assertNotContains($sent->id(), $ids);
        self::assertNull($this->query()->report($sent->id(), $month, self::now()));
        self::assertNull($this->query()->report($optedOut->id(), $month, self::now()));
        self::assertNull($this->query()->report($draft->id(), $month, self::now()));
        self::assertNull($this->query()->report('kein-uuid', $month, self::now()));
    }

    public function test_a_bare_entry_has_every_gap(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create(['name' => 'Leer']);
        $this->manager($venue, 'anna@example.ch', 'Anna');
        $this->connection->executeStatement('UPDATE venues SET inquiry_email = NULL, price_level = NULL, capacity_seated = NULL, capacity_standing = NULL WHERE id = ?', [$venue->id()]);

        $report = $this->query()->report($venue->id(), self::october(), self::now());

        self::assertNotNull($report);
        self::assertSame('Leer', $report->venueName);
        self::assertSame($venue->number(), $report->venueNumber);
        self::assertSame(ReportSuggestion::InquiryEmail, $report->suggestion);
        self::assertFalse($report->gaps->hasInquiryEmail);
        self::assertSame(0, $report->gaps->galleryPhotos);
        self::assertFalse($report->gaps->hasDescription);
        self::assertSame(0, $report->gaps->rooms);
        self::assertFalse($report->gaps->hasSeatedOrStanding);
        self::assertSame(0, $report->gaps->eventTypes);
        self::assertFalse($report->gaps->hasPriceLevel);
        self::assertTrue($report->noData);
        self::assertSame(['visits', 'interactions', 'delivered'], array_map(static fn ($f): string => $f->key, $report->figures));
        self::assertFalse($report->pro);
        self::assertNull($report->detailed);
        self::assertNull($report->trend);
    }

    public function test_the_gaps_read_the_entry(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create();
        $this->manager($venue, 'anna@example.ch', 'Anna');
        $this->connection->executeStatement("UPDATE venues SET inquiry_email = 'anfrage@example.ch', price_level = 'medium', capacity_seated = 80 WHERE id = ?", [$venue->id()]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'locale' => 'de']);
        RoomFactory::createOne(['venue' => $venue]);
        $this->connection->executeStatement("INSERT INTO venue_terms (venue_id, term_group, term_code) SELECT ?, term_group, code FROM taxonomy_terms WHERE term_group = 'suitable_for' ORDER BY position LIMIT 2", [$venue->id()]);

        $report = $this->query()->report($venue->id(), self::october(), self::now());

        self::assertNotNull($report);
        self::assertEquals(new ReportGaps(true, 0, 0, $report->plan->photosMax, true, 1, true, 2, true), $report->gaps);
        self::assertSame(ReportSuggestion::TitlePhoto, $report->suggestion);
    }

    public function test_the_report_goes_to_active_managers_only(): void
    {
        $venue = $this->venueWithInquiryAddress();
        $this->manager($venue, 'beat@example.ch', 'Beat');
        $this->manager($venue, 'anna@example.ch', 'Anna');
        $gone = PortalUserFactory::createOne(['email' => 'weg@example.ch']);
        PortalVenueAccessFactory::new()->active()->create(['user' => $gone, 'venue' => $venue]);
        $this->connection->executeStatement('UPDATE portal_users SET is_active = false WHERE id = ?', [$gone->id()]);

        $report = $this->query()->report($venue->id(), self::october(), self::now());

        self::assertNotNull($report);
        self::assertSame([['email' => 'anna@example.ch', 'name' => 'Anna'], ['email' => 'beat@example.ch', 'name' => 'Beat']], $report->recipients);
    }

    public function test_without_a_manager_a_venue_is_not_due_while_the_fallback_is_off(): void
    {
        // D52 as settled by the lead: OwnerReportQuery::INQUIRY_ADDRESS_FALLBACK is off, managers only.
        $unclaimed = $this->venueWithInquiryAddress();
        $pending = $this->venueWithInquiryAddress();
        PortalVenueAccessFactory::createOne(['user' => PortalUserFactory::createOne(['email' => 'neu@example.ch']), 'venue' => $pending]);
        $inactive = $this->venueWithInquiryAddress();
        $this->manager($inactive, 'weg@example.ch', 'Weg');
        $this->connection->executeStatement("UPDATE portal_users SET is_active = false WHERE email = 'weg@example.ch'");

        $ids = $this->query()->due(self::october());

        foreach ([$unclaimed, $pending, $inactive] as $venue) {
            self::assertNotContains($venue->id(), $ids);
            self::assertNull($this->query()->report($venue->id(), self::october(), self::now()), 'no figures without a recipient');
        }
    }

    public function test_a_manager_who_left_after_due_gets_no_report(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create();
        $this->manager($venue, 'anna@example.ch', 'Anna');
        self::assertContains($venue->id(), $this->query()->due(self::october()));

        $this->connection->executeStatement('DELETE FROM portal_venue_access WHERE venue_id = ?', [$venue->id()]);

        self::assertNull($this->query()->report($venue->id(), self::october(), self::now()));
    }

    public function test_the_fallback_switched_on_takes_the_inquiry_address(): void
    {
        $venue = $this->venueWithInquiryAddress();

        self::assertContains($venue->id(), $this->query(inquiryAddressFallback: true)->due(self::october()));
        $report = $this->query(inquiryAddressFallback: true)->report($venue->id(), self::october(), self::now());

        self::assertNotNull($report);
        self::assertSame([['email' => 'anfrage@example.ch', 'name' => null]], $report->recipients);
    }

    public function test_a_pro_entry_gets_the_detailed_report(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create(['tier' => KnownTiers::PRO]);
        $this->manager($venue, 'anna@example.ch', 'Anna');

        $report = $this->query()->report($venue->id(), self::october(), self::now());

        self::assertNotNull($report);
        self::assertTrue($report->pro);
        self::assertNotNull($report->detailed);
        self::assertNotNull($report->trend);
        self::assertSame(['Oktober 2026', 'September 2026', 'August 2026'], array_column($report->trend, 'label'));
        self::assertNotNull($report->plan->paidUntil);
    }

    public function test_the_opt_out_state(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create(['name' => 'Seehaus']);

        self::assertEquals(new ReportOptOutState('Seehaus', false), $this->query()->optOutState($venue->id()));
        ReportOptOutFactory::createOne(['venue' => $venue]);
        self::assertEquals(new ReportOptOutState('Seehaus', true), $this->query()->optOutState($venue->id()));
        self::assertNull($this->query()->optOutState('0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'));
    }

    private function venueWithInquiryAddress(): Venue
    {
        $venue = VenueFactory::new()->withoutLocation()->create();
        $this->connection->executeStatement("UPDATE venues SET inquiry_email = 'anfrage@example.ch' WHERE id = ?", [$venue->id()]);

        return $venue;
    }

    private function manager(Venue $venue, string $email, string $firstName): void
    {
        $user = PortalUserFactory::createOne(['email' => $email, 'firstName' => $firstName]);
        PortalVenueAccessFactory::new()->active()->create(['user' => $user, 'venue' => $venue]);
    }

    private function query(bool $inquiryAddressFallback = OwnerReportQuery::INQUIRY_ADDRESS_FALLBACK): OwnerReportQuery
    {
        $container = self::getContainer();
        $services = [];
        foreach ([VenueMonthQuery::class, SiteStatsQuery::class, LocationPlanQuery::class, EntitlementResolver::class, VenueMailRecipients::class, EnabledLocale::class] as $class) {
            $service = $container->get($class);
            self::assertInstanceOf($class, $service);
            $services[] = $service;
        }

        return new OwnerReportQuery($this->connection, ...$services, inquiryAddressFallback: $inquiryAddressFallback);
    }

    private static function october(): ReportMonth
    {
        return ReportMonth::before(self::now());
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }
}
