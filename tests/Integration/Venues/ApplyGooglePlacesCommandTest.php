<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Command\AssignVenueGooglePlace;
use App\Venues\GooglePlaceFinder;
use App\Venues\VenueCoordinateFiller;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * `app:venues:apply-google-places` (#53, D6; #57) on a checked report: only `yes` rows are set, through the audited
 * command; a venue's place ID is never overwritten and a kept venue gets no audit row; an ID that is not one is
 * refused; a dry run writes nothing and counts a #57 report by confidence; a #53 report still applies. The output
 * names venue numbers, never a place ID.
 */
final class ApplyGooglePlacesCommandTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;
    use RollsBackDatabase {
        tearDown as private rollBack;
    }

    private const string KEPT_ID = 'ChIJStaffWork0001';

    protected function tearDown(): void
    {
        @unlink($this->reportFile());
        $this->rollBack();
    }

    public function test_only_yes_rows_are_set_audited_and_purged(): void
    {
        $yes = DetailVenues::inCantonOnly();
        $empty = DetailVenues::withoutPlace();
        $this->report([
            [$yes->number(), 'ChIJScheune00001', ' Yes '],
            [$empty->number(), 'ChIJWiesenhof0002', ''],
        ]);

        [$status, $output] = $this->apply();

        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertStringContainsString('1 assigned, 0 kept (already had a place ID), 0 refused (not a place ID), 0 unknown venue.', $output);
        self::assertStringNotContainsString('ChIJ', $output);
        self::assertSame('ChIJScheune00001', $this->placeId($yes->number()));
        self::assertNull($this->placeId($empty->number()));
        self::assertNull($this->connection->fetchOne('SELECT cms_edited_at FROM venues WHERE number = ?', [$yes->number()]));
        $audit = $this->connection->fetchAssociative("SELECT actor_type, command, changes FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $yes->number()]);
        self::assertIsArray($audit);
        self::assertSame(['console', AssignVenueGooglePlace::class, ['changed' => ['googlePlaceId']]], [$audit['actor_type'], $audit['command'], json_decode((string) $audit['changes'], true)]);
        self::assertSame([$yes->number()], $this->purger()->venues);
        self::assertSame(0, $this->purger()->listings);
    }

    public function test_a_dry_run_says_the_same_and_writes_nothing(): void
    {
        $yes = DetailVenues::inCantonOnly();
        $kept = DetailVenues::withoutPlace();
        $this->connection->executeStatement('UPDATE venues SET google_place_id = ? WHERE number = ?', [self::KEPT_ID, $kept->number()]);
        $this->em->clear();
        $this->report([
            [$yes->number(), 'ChIJScheune00001', 'yes'],
            [$kept->number(), 'ChIJWiesenhof0002', 'yes'],
        ]);
        $audit = (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log');

        [$status, $output] = $this->apply(dryRun: true);

        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertStringContainsString('Dry run: 1 assigned, 1 kept (already had a place ID), 0 refused (not a place ID), 0 unknown venue.', $output);
        self::assertStringContainsString('Kept: '.$kept->number(), $output);
        self::assertNull($this->placeId($yes->number()));
        self::assertSame($audit, (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log'));
        self::assertSame([], $this->purger()->venues);
    }

    public function test_a_place_id_is_kept_a_bad_one_refused_and_an_unknown_venue_named(): void
    {
        $kept = DetailVenues::inCantonOnly();
        $bad = DetailVenues::withoutPlace();
        $this->connection->executeStatement('UPDATE venues SET google_place_id = ? WHERE number = ?', [self::KEPT_ID, $kept->number()]);
        $this->em->clear();
        $this->report([
            [$kept->number(), 'ChIJScheune00001', 'yes'],
            [$bad->number(), 'ChIJ <b>', 'yes'],
            [999999, 'ChIJNiemand00003', 'yes'],
        ]);
        $keptAudit = "SELECT count(*) FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?";

        [$status, $output] = $this->apply();

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('0 assigned, 1 kept (already had a place ID), 1 refused (not a place ID), 1 unknown venue.', $output);
        self::assertStringContainsString('Kept: '.$kept->number(), $output);
        self::assertStringContainsString('Refused: '.$bad->number(), $output);
        self::assertStringContainsString('Unknown: 999999', $output);
        self::assertStringNotContainsString('ChIJ', $output);
        self::assertSame(self::KEPT_ID, $this->placeId($kept->number()));
        self::assertNull($this->placeId($bad->number()));
        self::assertSame(0, (int) $this->connection->fetchOne($keptAudit, [(string) $kept->number()]), 'a kept venue gets no audit row');
    }

    public function test_a_dry_run_counts_the_report_by_confidence(): void
    {
        $confident = DetailVenues::inCantonOnly();
        $checked = DetailVenues::withoutPlace();
        $this->report([
            [$confident->number(), 'ChIJScheune00001', 'yes', 'confident'],
            [$checked->number(), 'ChIJWiesenhof0002', 'yes', 'doubtful'],
            [999998, '', '', 'not found'],
            [999999, '', '', 'failed'],
        ]);

        [$status, $output] = $this->apply(dryRun: true);

        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertStringContainsString("Report: 1 confident, 1 doubtful, 1 not found, 1 failed; 2 rows marked yes.\nDry run: 2 assigned, 0 kept (already had a place ID), 0 refused (not a place ID), 0 unknown venue.", $output);
        self::assertStringNotContainsString('ChIJ', $output);
        self::assertNull($this->placeId($confident->number()));
    }

    public function test_a_report_of_53_still_applies_its_yes_rows(): void
    {
        $yes = DetailVenues::inCantonOnly();
        $empty = DetailVenues::withoutPlace();
        $this->write([
            ['number', 'name', 'published', 'status', 'query', 'place_id', 'matched_name', 'name_matches', 'address', 'distance_km', 'apply'],
            [(string) $yes->number(), 'Name', 'yes', 'found', 'Name Schweiz', 'ChIJScheune00001', 'Name', 'yes', 'Adresse', '0.1', 'yes'],
            [(string) $empty->number(), 'Name', 'yes', 'found', 'Name Schweiz', 'ChIJWiesenhof0002', 'Name', 'NO', 'Adresse', '9.0', ''],
        ]);

        [$dryStatus, $dryOutput] = $this->apply(dryRun: true);

        self::assertSame(Command::SUCCESS, $dryStatus, $dryOutput);
        self::assertStringNotContainsString('Report:', $dryOutput);
        self::assertStringContainsString('Dry run: 1 assigned, 0 kept', $dryOutput);

        [$status, $output] = $this->apply();

        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertStringContainsString('1 assigned, 0 kept (already had a place ID), 0 refused (not a place ID), 0 unknown venue.', $output);
        self::assertSame('ChIJScheune00001', $this->placeId($yes->number()));
        self::assertNull($this->placeId($empty->number()));
    }

    public function test_a_second_apply_assigns_nothing(): void
    {
        $venue = DetailVenues::inCantonOnly();
        $this->report([[$venue->number(), 'ChIJScheune00001', 'yes']]);

        [$first] = $this->apply();
        $this->em->clear();
        $after = $this->connection->fetchAssociative('SELECT google_place_id, updated_at FROM venues WHERE number = ?', [$venue->number()]);
        $audit = (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log');
        [$status, $output] = $this->apply();

        self::assertSame([Command::SUCCESS, Command::SUCCESS], [$first, $status]);
        self::assertStringContainsString('0 assigned, 1 kept (already had a place ID), 0 refused (not a place ID), 0 unknown venue.', $output);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $venue->number()]), 'one audit row only');
        self::assertSame($audit, (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log'), 'the second run writes no audit row');
        self::assertSame(['google_place_id' => 'ChIJScheune00001'], array_intersect_key((array) $after, ['google_place_id' => true]));
        self::assertSame($after, $this->connection->fetchAssociative('SELECT google_place_id, updated_at FROM venues WHERE number = ?', [$venue->number()]), 'the second run changes nothing on the venue');
    }

    public function test_without_a_report_it_fails(): void
    {
        [$status, $output] = $this->apply();

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('app:venues:find-google-places first.', $output);
    }

    /**
     * A #57 report, each row built by column name.
     *
     * @param list<array{0: int, 1: string, 2: string, 3?: string}> $rows venue number, place ID, apply, confidence (default: confident)
     */
    private function report(array $rows): void
    {
        $lines = [GooglePlaceFinder::HEADER];
        foreach ($rows as $row) {
            [$number, $placeId, $apply] = $row;
            $cells = [
                'number' => (string) $number, 'name' => 'Name', 'published' => 'yes', 'confidence' => $row[3] ?? 'confident',
                'reason' => 'Grund', 'apply' => $apply, 'maps_url' => 'https://www.google.com/maps', 'place_id' => $placeId,
                'google_name' => 'Name', 'google_address' => 'Adresse', 'distance_km' => '0.1', 'query' => 'Name Schweiz',
            ];
            $lines[] = array_map(static fn (string $column): string => $cells[$column], GooglePlaceFinder::HEADER);
        }
        $this->write($lines);
    }

    /**
     * @param list<list<string>> $lines
     */
    private function write(array $lines): void
    {
        $file = $this->reportFile();
        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), 0775, true);
        }
        file_put_contents($file, VenueCoordinateFiller::csv($lines));
    }

    /**
     * @return array{int, string}
     */
    private function apply(bool $dryRun = false): array
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:venues:apply-google-places'));
        $status = $tester->execute($dryRun ? ['--dry-run' => true] : []);

        return [$status, $tester->getDisplay()];
    }

    private function placeId(int $number): mixed
    {
        return $this->connection->fetchOne('SELECT google_place_id FROM venues WHERE number = ?', [$number]);
    }

    private function reportFile(): string
    {
        $root = self::getContainer()->getParameter('app.content_root');
        \assert(\is_string($root));

        return $root.'/'.GooglePlaceFinder::REPORT_FILE;
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);

        return $purger;
    }
}
