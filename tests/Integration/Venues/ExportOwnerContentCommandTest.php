<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Tests\Support\Content\WixCsv;
use App\Tests\Support\Http\WixImageResponses;
use App\Tests\Support\Media\TestImages;
use App\Venues\LegacyVenueText;
use App\Venues\OwnerContentExporter;
use App\Venues\ValueObject\Content\OwnerExportReport;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Yaml\Yaml;

/**
 * app:owner:export-content on a fictional CSV built at runtime, over folders #21's export
 * wrote, with every Wix response from the test transport. The import half uses
 * RunsContentImport, so the output is proven through #9's real reader and handler.
 */
final class ExportOwnerContentCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use RunsContentImport {
        setUp as private setUpImport;
    }

    private const string NEWER_ID = '2b3c4d5e-0000-4000-8000-000000000002';
    private const string LIECHTENSTEIN_ID = '3c4d5e6f-0000-4000-8000-000000000003';
    private const string OLDER_NAME = 'Landgasthof Beispielhof';
    private const string NEWER_NAME = 'Hotel Erfunden';

    private const string COVER = 'aa11_fiktiv~mv2.jpg';
    private const string FIRST = 'bb22_fiktiv~mv2.jpg';
    private const string SECOND = 'cc33_fiktiv~mv2.jpg';
    private const string NEWER_COVER = 'dd44_fiktiv~mv2.jpg';
    private const string NEWER_PHOTO = 'ee55_fiktiv~mv2.jpg';

    private const string OLDER_ALT = 'Eine erfundene Scheune im Abendlicht';
    private const string NEWER_ALT = 'Ein fiktiver Saal mit gedeckten Tischen';

    private string $csv;
    private CommandTester $legacy;
    private CommandTester $owner;
    private WixImageResponses $wix;

    protected function setUp(): void
    {
        $this->setUpImport();
        self::mockTime('2026-10-30 12:00:00');
        $this->csv = $this->content->path().'/owner/owner.csv';
        \assert(null !== self::$kernel);
        $application = new Application(self::$kernel);
        $this->legacy = new CommandTester($application->find('app:legacy:export-content'));
        $this->owner = new CommandTester($application->find('app:owner:export-content'));
        $wix = self::getContainer()->get(WixImageResponses::class);
        \assert($wix instanceof WixImageResponses);
        $this->wix = $wix;
        foreach ([self::COVER, self::FIRST, self::SECOND, self::NEWER_COVER, self::NEWER_PHOTO] as $n => $id) {
            $this->wix->image($id, self::image($n + 1));
        }
    }

    public function test_both_form_generations_reach_the_database_through_the_import(): void
    {
        $this->exportBoth([self::olderRow(), self::newerRow()]);

        self::assertSame(Command::SUCCESS, $this->import());
        self::assertStringContainsString('Venues: 2 seen, 2 created, 0 updated, 0 unchanged, 0 failed', $this->display());

        $older = $this->venueRow(self::key());
        \assert(false !== $older);
        self::assertSame(['medium', 120, 150, null, true, null], [$older['price_level'], $older['capacity_seated'], $older['capacity_standing'], $older['party'], $older['has_curfew'], $older['own_catering']]);
        self::assertSame('02:00', $this->curfew(self::key()));
        self::assertSame([
            'amenity.dance_floor', 'building.farm_barn*', 'ceremony.chapel', 'ceremony.civil_ceremony', 'dining.vegan', 'dining.vegetarian',
            'language.de', 'language.en', 'setting.countryside*', 'suitable_for.wedding*', 'surroundings.garden', 'usage.exclusive', 'vendor.dj', 'vendor.photography',
        ], $this->terms(self::key()));
        self::assertSame(['Ländlich', null, null, null, null], $this->texts(self::key()));
        self::assertSame([[1, null], [2, self::OLDER_ALT], [3, null]], $this->photoRows(self::key()));

        $newer = $this->venueRow(self::newerKey());
        \assert(false !== $newer);
        self::assertSame(['exclusive', 120, 100, 'yes', true, 'allowed'], [$newer['price_level'], $newer['capacity_seated'], $newer['capacity_standing'], $newer['party'], $newer['has_curfew'], $newer['own_catering']]);
        self::assertSame('00:00', $this->curfew(self::newerKey()));
        self::assertSame([
            'amenity.dance_floor', 'amenity.external_caterers', 'amenity.parking', 'building.hotel*', 'building.restaurant', 'ceremony.civil_ceremony', 'ceremony.free_ceremony',
            'dining.gluten_free', 'dining.kids_menu', 'language.de', 'language.fr', 'setting.city', 'setting.lake', 'suitable_for.wedding*',
            'usage.wedding_and_regular', 'vendor.officiant', 'vendor.wedding_planner',
        ], $this->terms(self::newerKey()));
        [$ambience, $capacityNote, $partyNote, $openingHours, $parkingText] = $this->texts(self::newerKey());
        self::assertSame(['Urban am See', 'Gesetztes Essen: max. 120 Personen', 'Mai bis Oktober, täglich', '25 Parkplätze'], [$ambience, $capacityNote, $openingHours, $parkingText]);
        self::assertIsString($partyNote);
        self::assertStringContainsString('24 Uhr', $partyNote);
        self::assertSame([[1, self::NEWER_ALT], [2, null]], $this->photoRows(self::newerKey()));
        self::assertSame(2, $this->rows('venue_photos p JOIN venues v ON v.id = p.venue_id WHERE v.content_key = ? AND p.variants IS NOT NULL', [self::newerKey()]));
    }

    public function test_a_second_run_changes_nothing_and_asks_wix_for_nothing(): void
    {
        $this->exportBoth([self::olderRow(), self::newerRow()]);
        self::assertSame(Command::SUCCESS, $this->import());
        $bytes = $this->folderBytes();
        $requests = \count($this->wix->requested());
        self::assertSame(5, $requests);

        self::assertSame(Command::SUCCESS, $this->ownerExport());
        self::assertStringContainsString('Rows: 2. Overlaid: 2 (0 updated, 2 unchanged).', $this->owner->getDisplay());
        self::assertStringContainsString('Photos: 5 listed, 5 cached, 0 downloaded', $this->owner->getDisplay());
        self::assertSame(Command::SUCCESS, $this->import());

        self::assertStringContainsString('0 created, 0 updated, 2 unchanged, 0 failed', $this->display());
        self::assertSame($bytes, $this->folderBytes());
        self::assertCount($requests, $this->wix->requested());
    }

    public function test_the_two_exports_keep_each_others_bytes_in_either_order(): void
    {
        $this->exportBoth([self::olderRow(), self::newerRow()]);
        $bytes = $this->folderBytes();

        self::assertSame(Command::SUCCESS, $this->legacyExport());
        self::assertStringContainsString('(0 created, 0 updated, 2 unchanged)', $this->legacy->getDisplay());
        self::assertSame($bytes, $this->folderBytes());

        self::assertSame(Command::SUCCESS, $this->ownerExport());
        self::assertStringContainsString('(0 updated, 2 unchanged)', $this->owner->getDisplay());
        self::assertSame($bytes, $this->folderBytes());
    }

    public function test_keys_outside_the_owned_list_survive(): void
    {
        $this->exportBoth([self::olderRow()]);
        $data = $this->yaml(self::key());
        $data['tier'] = 'premium';
        $data['faq'] = ['daylight' => true];
        \assert(\is_array($data['terms']));
        $data['terms']['furniture'] = ['chairs'];
        $data['rooms'] = [['key' => 'saal', 'translations' => ['de' => ['name' => 'Erfundener Saal']]]];
        file_put_contents($this->file(self::key()), Yaml::dump($data, 8, 2));

        self::assertSame(Command::SUCCESS, $this->ownerExport());
        $after = $this->yaml(self::key());

        self::assertSame('premium', $after['tier']);
        self::assertSame(['daylight' => true], $after['faq']);
        \assert(\is_array($after['terms']));
        self::assertSame(['chairs'], $after['terms']['furniture']);
        self::assertSame($data['rooms'], $after['rooms']);
        self::assertSame(Command::SUCCESS, $this->import());
    }

    public function test_a_fact_the_csv_no_longer_holds_is_removed_and_reset_by_the_import(): void
    {
        $this->exportBoth([self::newerRow()]);
        self::assertSame(Command::SUCCESS, $this->import());

        $this->csv([self::newerRow(['Preisniveau' => '', 'Öffnungszeiten' => '', 'Vorstellungstext' => WixCsv::newerForm(['Party möglich?' => null, 'Infrastruktur:' => ['Tanzfläche']], [WixCsv::photo(self::NEWER_PHOTO, self::NEWER_ALT)])])]);
        self::assertSame(Command::SUCCESS, $this->ownerExport());
        $data = $this->yaml(self::newerKey());

        self::assertArrayNotHasKey('price_level', $data);
        self::assertArrayNotHasKey('party', $data);
        self::assertArrayNotHasKey('faq', $data);
        $de = self::de($data);
        self::assertArrayNotHasKey('opening_hours', $de);
        self::assertArrayNotHasKey('parking_text', $de);
        self::assertSame(Command::SUCCESS, $this->import());
        $row = $this->venueRow(self::newerKey());
        \assert(false !== $row);
        self::assertSame([null, null, null, null], [$row['price_level'], $row['party'], $row['has_curfew'], $row['own_catering']]);
    }

    public function test_a_row_without_a_folder_is_unresolved_and_the_others_are_written(): void
    {
        $this->csv([self::olderRow()]);
        self::assertSame(Command::SUCCESS, $this->legacyExport());
        $this->csv([self::olderRow(), self::newerRow()]);

        self::assertSame(Command::FAILURE, $this->ownerExport());

        self::assertStringContainsString('row 2: no content folder for this row; run app:legacy:export-content first', $this->owner->getDisplay());
        self::assertDirectoryDoesNotExist($this->content->path().'/venues/'.self::newerKey());
        self::assertArrayHasKey('price_level', $this->yaml(self::key()));
        self::assertNotContains('https://static.wixstatic.com/media/'.self::NEWER_COVER, $this->wix->requested());
    }

    public function test_an_avif_cover_is_left_out_without_a_request_and_the_next_photo_leads(): void
    {
        $this->exportBoth([self::olderRow(['Anzeigebild' => WixCsv::cover('ff66_fiktiv~mv2.avif')])]);

        self::assertStringContainsString('photo 1 left out: '.OwnerContentExporter::LEFT_OUT_AVIF, $this->owner->getDisplay());
        self::assertNotContains('https://static.wixstatic.com/media/ff66_fiktiv~mv2.avif', $this->wix->requested());
        self::assertSame(Command::SUCCESS, $this->import());
        self::assertSame([[1, self::OLDER_ALT], [2, null]], $this->photoRows(self::key()));
    }

    public function test_a_bad_file_and_a_duplicate_are_left_out_and_the_venue_imports(): void
    {
        $this->wix->image(self::FIRST, TestImages::avif());
        $this->wix->image(self::SECOND, self::image(1));

        self::assertSame(Command::SUCCESS, $this->exportBoth([self::olderRow()]));

        self::assertStringContainsString('photo 2 left out: not a JPEG, PNG or WebP image', $this->owner->getDisplay());
        self::assertStringContainsString('photo 3 left out: the same image as an earlier photo', $this->owner->getDisplay());
        self::assertSame(Command::SUCCESS, $this->import());
        self::assertSame([[1, null]], $this->photoRows(self::key()));
        $row = $this->venueRow(self::key());
        \assert(false !== $row);
        self::assertSame('medium', $row['price_level']);
    }

    public function test_a_failed_download_exits_1_and_the_next_run_fills_the_gap(): void
    {
        $this->wix->respond(self::SECOND, new MockResponse('', ['http_code' => 404]), new MockResponse(self::image(3), ['response_headers' => ['content-length' => (string) \strlen(self::image(3))]]));

        self::assertSame(Command::FAILURE, $this->exportBoth([self::olderRow()]));
        self::assertStringContainsString('photo 3 left out: '.OwnerContentExporter::LEFT_OUT_DOWNLOAD, $this->owner->getDisplay());
        self::assertSame(Command::SUCCESS, $this->import());
        self::assertCount(2, $this->photoRows(self::key()));

        self::assertSame(Command::SUCCESS, $this->ownerExport());
        self::assertStringContainsString('1 downloaded', $this->owner->getDisplay());
        self::assertSame(Command::SUCCESS, $this->import());
        self::assertCount(3, $this->photoRows(self::key()));
    }

    public function test_a_photo_that_left_the_list_is_deleted_and_the_import_removes_it(): void
    {
        $this->exportBoth([self::olderRow()]);
        self::assertSame(Command::SUCCESS, $this->import());
        touch($this->content->path().'/venues/'.self::key().'/photos/.DS_Store');

        $this->csv([self::olderRow(['Vorstellungstext' => WixCsv::olderForm([], [WixCsv::photo(self::FIRST, self::OLDER_ALT)])])]);
        self::assertSame(Command::SUCCESS, $this->ownerExport());

        $photos = $this->content->path().'/venues/'.self::key().'/photos';
        self::assertFileDoesNotExist($photos.'/'.self::SECOND);
        self::assertFileExists($photos.'/.DS_Store');
        foreach ([self::COVER, self::FIRST] as $id) {
            self::assertFalse(is_link($photos.'/'.$id));
            self::assertFileExists($photos.'/'.$id);
        }
        self::assertSame(Command::SUCCESS, $this->import());
        self::assertSame([[1, null], [2, self::OLDER_ALT]], $this->photoRows(self::key()));
    }

    public function test_a_liechtenstein_row_makes_no_request_and_no_folder(): void
    {
        $row = self::newerRow(['Region' => 'Liechtenstein', 'ID' => self::LIECHTENSTEIN_ID, 'Telefon' => '+4233001122']);

        self::assertSame(Command::SUCCESS, $this->exportBoth([self::olderRow(), $row]));

        self::assertStringContainsString('Skipped: 1. Unresolved: 0.', $this->owner->getDisplay());
        self::assertCount(1, (array) glob($this->content->path().'/venues/*', \GLOB_ONLYDIR));
        self::assertNotContains('https://static.wixstatic.com/media/'.self::NEWER_COVER, $this->wix->requested());
    }

    public function test_a_dry_run_makes_no_request_and_writes_only_the_report(): void
    {
        $this->csv([self::olderRow()]);
        self::assertSame(Command::SUCCESS, $this->legacyExport());
        $bytes = $this->folderBytes();

        self::assertSame(Command::SUCCESS, $this->ownerExport(['--dry-run' => true]));

        self::assertStringContainsString('Dry run: Rows: 1. Overlaid: 1 (1 updated, 0 unchanged).', $this->owner->getDisplay());
        self::assertStringContainsString('3 would download', $this->owner->getDisplay());
        self::assertSame([], $this->wix->requested());
        self::assertSame($bytes, $this->folderBytes());
        self::assertDirectoryDoesNotExist($this->content->path().'/'.OwnerContentExporter::PHOTO_CACHE);
        self::assertFileExists($this->content->path().'/'.OwnerContentExporter::REPORT_FILE);
    }

    public function test_the_console_shows_no_cell_and_the_report_file_holds_the_details(): void
    {
        $older = self::olderRow(['Vorstellungstext' => WixCsv::olderForm(['Service:' => ['Fotograf', 'Zauberer am erfundenen Teich']], [WixCsv::photo(self::FIRST, self::OLDER_ALT)])]);

        self::assertSame(Command::SUCCESS, $this->exportBoth([$older]));

        $display = $this->owner->getDisplay();
        foreach ([WixCsv::PHONE, WixCsv::PHONE_E164, WixCsv::EMAIL, self::OLDER_ALT, 'Zauberer am erfundenen Teich', self::FIRST, 'Ländlich'] as $cell) {
            self::assertStringNotContainsString($cell, $display);
        }
        $report = (string) file_get_contents($this->content->path().'/'.OwnerContentExporter::REPORT_FILE);
        self::assertStringContainsString('Zauberer am erfundenen Teich', $report);
        self::assertStringContainsString('## '.OwnerExportReport::SPOT_CHECK_TITLE, $report);
        self::assertStringContainsString('- '.self::key(), $report);
    }

    public function test_curfew_at_round_trips_including_midnight(): void
    {
        $this->exportBoth([self::olderRow(), self::newerRow()]);

        self::assertStringContainsString("curfew_at: '02:00'", (string) file_get_contents($this->file(self::key())));
        self::assertStringContainsString("curfew_at: '00:00'", (string) file_get_contents($this->file(self::newerKey())));
        self::assertSame(Command::SUCCESS, $this->import());
        self::assertSame('02:00', $this->curfew(self::key()));
        self::assertSame('00:00', $this->curfew(self::newerKey()));
    }

    /**
     * An older-form venue: Einrichtung, Service, Essen / Trinken, Sperrstunde.
     *
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private static function olderRow(array $overrides = []): array
    {
        return WixCsv::row($overrides + [
            'Name' => self::OLDER_NAME,
            'Anzeigebild' => WixCsv::cover(self::COVER),
            'Vorstellungstext' => WixCsv::olderForm([], [WixCsv::photo(self::FIRST, self::OLDER_ALT), WixCsv::photo(self::SECOND)]),
        ]);
    }

    /**
     * A newer-form venue: Infrastruktur, Zeremonie, Essen, Party möglich?, two settings.
     *
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private static function newerRow(array $overrides = []): array
    {
        return WixCsv::row($overrides + [
            'Name' => self::NEWER_NAME,
            'ID' => self::NEWER_ID,
            'Typ' => 'Hotel Restaurant',
            'Ambiente' => 'Urban am See',
            'Preisniveau' => 'Exklusiv',
            'Öffnungszeiten' => 'Mai bis Oktober, täglich',
            'Anzeigebild' => WixCsv::cover(self::NEWER_COVER),
            'Vorstellungstext' => WixCsv::newerForm([
                'Kapazität für gesetztes Essen:' => 'max. 120 Personen',
                'Infrastruktur:' => ['Tanzfläche', 'Parkplätze', '25 Parkplätze', 'Externe Caterer erlaubt'],
                'Party möglich?' => 'Ja, bis 24 Uhr',
            ], [WixCsv::photo(self::NEWER_COVER, self::NEWER_ALT), WixCsv::photo(self::NEWER_PHOTO)]),
        ]);
    }

    /**
     * Writes the CSV, runs #21's export, then #32's; returns #32's exit code.
     *
     * @param list<array<string, string>> $rows
     */
    private function exportBoth(array $rows): int
    {
        $this->csv($rows);
        self::assertSame(Command::SUCCESS, $this->legacyExport(), $this->legacy->getDisplay());

        return $this->ownerExport();
    }

    /**
     * @param list<array<string, string>> $rows
     */
    private function csv(array $rows): void
    {
        WixCsv::write($this->csv, $rows);
    }

    private function legacyExport(): int
    {
        return $this->legacy->execute(['csv' => $this->csv, 'path' => $this->content->path()]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function ownerExport(array $options = []): int
    {
        return $this->owner->execute(['csv' => $this->csv, 'path' => $this->content->path(), ...$options]);
    }

    private static function key(string $name = self::OLDER_NAME, string $id = WixCsv::ID): string
    {
        return LegacyVenueText::key($name, substr($id, 0, 8));
    }

    private static function newerKey(): string
    {
        return self::key(self::NEWER_NAME, self::NEWER_ID);
    }

    private function file(string $key): string
    {
        return $this->content->path().'/venues/'.$key.'/venue.yaml';
    }

    /**
     * @return array<string, mixed>
     */
    private function yaml(string $key): array
    {
        $data = Yaml::parseFile($this->file($key));
        \assert(\is_array($data));

        /* @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<mixed>
     */
    private static function de(array $data): array
    {
        $translations = $data['translations'] ?? null;
        \assert(\is_array($translations) && \is_array($translations['de'] ?? null));

        return $translations['de'];
    }

    /**
     * Every file under venues/ by path, with its bytes.
     *
     * @return array<string, string>
     */
    private function folderBytes(): array
    {
        $bytes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->content->path().'/venues', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $bytes[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
        ksort($bytes);

        return $bytes;
    }

    private function curfew(string $key): ?string
    {
        $value = $this->connection->fetchOne("SELECT to_char(curfew_at, 'HH24:MI') FROM venues WHERE content_key = ?", [$key]);

        return \is_string($value) ? $value : null;
    }

    /**
     * `group.code`, with `*` for a main term, sorted.
     *
     * @return list<string>
     */
    private function terms(string $key): array
    {
        /** @var list<string> $terms */
        $terms = $this->connection->fetchFirstColumn(
            "SELECT t.term_group || '.' || t.term_code || CASE WHEN t.is_main THEN '*' ELSE '' END FROM venue_terms t JOIN venues v ON v.id = t.venue_id WHERE v.content_key = ? ORDER BY 1",
            [$key],
        );

        return $terms;
    }

    /**
     * ambience, capacity_note, party_note, opening_hours, parking_text of the de translation.
     *
     * @return list<?string>
     */
    private function texts(string $key): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT t.ambience, t.capacity_note, t.party_note, t.opening_hours, t.parking_text FROM venue_translations t JOIN venues v ON v.id = t.venue_id WHERE v.content_key = ? AND t.locale = 'de'",
            [$key],
        );
        \assert(false !== $row);

        /** @var list<?string> $texts */
        $texts = array_values($row);

        return $texts;
    }

    /**
     * The gallery photos: position and de alt text, in order.
     *
     * @return list<array{int, ?string}>
     */
    private function photoRows(string $key): array
    {
        $rows = $this->connection->fetchAllNumeric(
            "SELECT p.position, pt.alt FROM venue_photos p JOIN venues v ON v.id = p.venue_id LEFT JOIN venue_photo_translations pt ON pt.venue_photo_id = p.id AND pt.locale = 'de' WHERE v.content_key = ? AND p.room_id IS NULL ORDER BY p.position",
            [$key],
        );

        /* @var list<array{int, ?string}> $rows */
        return array_map(static fn (array $row): array => [(int) $row[0], $row[1]], $rows);
    }
}
