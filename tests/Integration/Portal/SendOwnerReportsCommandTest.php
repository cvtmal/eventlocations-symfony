<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Factory\OwnerReportFactory;
use App\Factory\PortalUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\ReportOptOutFactory;
use App\Factory\VenueFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * `app:portal:owner-reports` (G10, Wave G §4a.5, §4a.6), driven by the clock: last month's report goes once to each
 * published venue's active managers from the 1st to the 7th, after the stats ingest ran past the month; the second
 * run sends nothing; an opt-out is honoured; a venue without a manager is not even due (D52 as settled by the lead); a
 * mail that did not go out prints one line under `--quiet` and is retried; old send-log rows are purged.
 */
final class SendOwnerReportsCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use RollsBackDatabase {
        setUp as rollBackSetUp;
    }

    protected function setUp(): void
    {
        $this->rollBackSetUp();
        self::mockTime('2026-11-01 03:15:00 Europe/Zurich');
        $this->connection->executeStatement('DELETE FROM stats_ingest_files');
        $this->connection->executeStatement('DELETE FROM funnel_events');
        $this->connection->executeStatement('DELETE FROM stats_daily');
        $this->ingestedAt('2026-11-01 03:16:00 Europe/Zurich');
    }

    public function test_the_report_goes_to_the_managers_once(): void
    {
        $venue = $this->venue('Alpstube Seeblick');
        $this->manager($venue, 'anna@example.ch', 'Anna');
        $this->manager($venue, 'beat@example.ch', 'Beat');

        [$status, $output] = $this->report();

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('1 reports sent, 0 skipped, 0 failed, 0 old rows purged.', trim($output));
        self::assertEmailCount(2);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('anna@example.ch', $mail->getTo()[0]->getAddress());
        self::assertSame('Dein Monatsbericht für Alpstube Seeblick: Oktober 2026', $mail->getSubject());
        self::assertStringContainsString('Hallo Anna', (string) $mail->getHtmlBody());
        self::assertSame(['2026-10-01'], $this->rows($venue));

        [$status, $output] = $this->report();
        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('0 reports sent, 0 skipped, 0 failed, 0 old rows purged.', trim($output));
        self::assertEmailCount(2);
        self::assertSame(['2026-10-01'], $this->rows($venue), 'the second run writes nothing');
    }

    public function test_an_opted_out_venue_gets_nothing(): void
    {
        $venue = $this->venue('Abbestellt');
        $this->manager($venue, 'anna@example.ch', 'Anna');
        ReportOptOutFactory::createOne(['venue' => $venue]);

        self::assertSame('0 reports sent, 0 skipped, 0 failed, 0 old rows purged.', trim($this->report()[1]));
        self::assertEmailCount(0);
        self::assertSame([], $this->rows($venue));
    }

    public function test_an_unclaimed_venue_gets_no_report_and_no_row(): void
    {
        $unclaimed = $this->venue('Ohne Konto');
        $this->connection->executeStatement("UPDATE venues SET inquiry_email = 'anfrage@example.ch' WHERE id = ?", [$unclaimed->id()]);
        $pending = $this->venue('Nur beantragt');
        PortalVenueAccessFactory::createOne(['user' => PortalUserFactory::createOne(['email' => 'neu@example.ch']), 'venue' => $pending]);

        self::assertSame('0 reports due for 2026-10.', trim($this->report(['--dry-run' => true])[1]), 'an unclaimed venue is not due');
        self::assertSame('0 reports sent, 0 skipped, 0 failed, 0 old rows purged.', trim($this->report()[1]));
        self::assertEmailCount(0);
        self::assertSame([], $this->rows($unclaimed), 'the inquiry address is no recipient while the fallback is off');
        self::assertSame([], $this->rows($pending), 'a pending request is no manager');
    }

    public function test_an_unpublished_venue_is_skipped(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create(['name' => 'Offline', 'status' => VenueStatus::Unpublished]);
        $this->manager($venue, 'anna@example.ch', 'Anna');

        self::assertSame('0 reports sent, 0 skipped, 0 failed, 0 old rows purged.', trim($this->report()[1]));
        self::assertEmailCount(0);
    }

    public function test_a_stale_ingest_sends_nothing(): void
    {
        $this->connection->executeStatement('DELETE FROM stats_ingest_files');
        $this->ingestedAt('2026-10-31 03:16:00 Europe/Zurich');
        $venue = $this->venue('Wartet');
        $this->manager($venue, 'anna@example.ch', 'Anna');

        [$status, $output] = $this->report();

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Stats not ingested past 2026-10; waiting.', $output);
        self::assertEmailCount(0);
        self::assertSame([], $this->rows($venue));
        self::assertSame('', $this->report([], OutputInterface::VERBOSITY_QUIET)[1], 'waiting is no failure: silent under --quiet');
    }

    public function test_after_the_seventh_only_a_named_month_is_sent(): void
    {
        self::mockTime('2026-11-08 03:15:00 Europe/Zurich');
        $venue = $this->venue('Spät');
        $this->manager($venue, 'anna@example.ch', 'Anna');

        self::assertSame('0 reports sent, 0 skipped, 0 failed, 0 old rows purged.', trim($this->report()[1]));
        self::assertEmailCount(0);

        self::assertSame('1 reports sent, 0 skipped, 0 failed, 0 old rows purged.', trim($this->report(['--month' => '2026-10'])[1]));
        self::assertSame(['2026-10-01'], $this->rows($venue));
        self::assertSame(Command::INVALID, $this->report(['--month' => '2026-13'])[0]);
    }

    public function test_a_dry_run_counts_and_writes_nothing(): void
    {
        $venue = $this->venue('Probe');
        $this->manager($venue, 'anna@example.ch', 'Anna');
        OwnerReportFactory::createOne(['venue' => $this->venue('Alt'), 'reportMonth' => new \DateTimeImmutable('2024-10-01')]);

        $this->venue('Ohne Konto');

        self::assertSame('1 reports due for 2026-10.', trim($this->report(['--dry-run' => true])[1]), 'only the venue with a manager');
        self::assertEmailCount(0);
        self::assertSame([], $this->rows($venue));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM portal_owner_reports'), 'a dry run purges nothing');
    }

    public function test_a_good_night_under_quiet_prints_nothing(): void
    {
        $this->manager($this->venue('Leise'), 'anna@example.ch', 'Anna');

        self::assertSame('', $this->report([], OutputInterface::VERBOSITY_QUIET)[1]);
        self::assertEmailCount(1);
    }

    public function test_rows_older_than_24_months_are_purged(): void
    {
        $old = $this->venue('Alt');
        $this->manager($old, 'anna@example.ch', 'Anna');
        OwnerReportFactory::createOne(['venue' => $old, 'reportMonth' => new \DateTimeImmutable('2024-10-01')]);
        OwnerReportFactory::createOne(['venue' => $old, 'reportMonth' => new \DateTimeImmutable('2024-11-01')]);

        self::assertSame('1 reports sent, 0 skipped, 0 failed, 1 old rows purged.', trim($this->report()[1]));
        self::assertSame(['2024-11-01', '2026-10-01'], $this->rows($old));
        self::assertSame('0 reports sent, 0 skipped, 0 failed, 0 old rows purged.', trim($this->report()[1]));
    }

    public function test_a_venue_without_data_still_gets_its_report(): void
    {
        $venue = $this->venue('Ruhig');
        $this->manager($venue, 'anna@example.ch', 'Anna');

        self::assertSame('1 reports sent, 0 skipped, 0 failed, 0 old rows purged.', trim($this->report()[1]));
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertStringContainsString('Im Oktober 2026 wurde «Ruhig» nicht aufgerufen', (string) $mail->getTextBody());
    }

    public function test_a_mail_that_did_not_go_out_writes_no_row(): void
    {
        $venue = $this->venue('Kaputt');
        $this->manager($venue, 'anna..falsch@example.ch', 'Anna');

        [$status, $output] = $this->report();

        self::assertSame(Command::SUCCESS, $status, 'the next night retries; no cron failure');
        self::assertSame(
            "0 reports sent, 0 skipped, 1 failed, 0 old rows purged.\n1 monthly reports failed; they are retried next night.",
            trim($output),
        );
        self::assertEmailCount(0);
        self::assertSame([], $this->rows($venue), 'no row: the next night tries again');
        self::assertSame(
            '1 monthly reports failed; they are retried next night.',
            trim($this->report([], OutputInterface::VERBOSITY_QUIET)[1]),
            'one line under --quiet',
        );
    }

    private function venue(string $name): Venue
    {
        return VenueFactory::new()->withoutLocation()->create(['name' => $name]);
    }

    private function manager(Venue $venue, string $email, string $firstName): void
    {
        $user = PortalUserFactory::createOne(['email' => $email, 'firstName' => $firstName]);
        PortalVenueAccessFactory::new()->active()->create(['user' => $user, 'venue' => $venue]);
    }

    private function ingestedAt(string $at): void
    {
        $this->connection->insert('stats_ingest_files', ['id' => EntityId::generate(), 'file_name' => 'test-'.substr(md5($at), 0, 8), 'events' => 1, 'created_at' => new \DateTimeImmutable($at)->format(\DateTimeInterface::ATOM)]);
    }

    /**
     * @return list<string>
     */
    private function rows(Venue $venue): array
    {
        return array_map(strval(...), $this->connection->fetchFirstColumn('SELECT report_month FROM portal_owner_reports WHERE venue_id = ? ORDER BY report_month', [$venue->id()]));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private function report(array $input = [], int $verbosity = OutputInterface::VERBOSITY_NORMAL): array
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:portal:owner-reports'));
        $status = $tester->execute($input, ['verbosity' => $verbosity]);

        return [$status, $tester->getDisplay()];
    }
}
