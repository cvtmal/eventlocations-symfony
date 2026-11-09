<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues\Sheet;

use App\Venues\ContentSchema;
use App\Venues\Sheet\VenueSheet;
use App\Venues\Sheet\VenueSheetRow;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The venue sheet (F14): the fictional template converts into draft documents, a consent row without its yes is
 * refused, and the file reads with `,` or `;` and with a byte order mark.
 */
final class VenueSheetTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/../../../Fixtures/Onboarding/venue-sheet.csv';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/venue-sheet-'.uniqid();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->directory.'/*') ?: []);
        rmdir($this->directory);
    }

    public function test_the_template_converts_into_drafts_and_refuses_a_consent_row_without_its_yes(): void
    {
        $rows = VenueSheet::read(self::FIXTURE);

        self::assertSame([2, 3, 4, 5, 6], array_map(static fn (VenueSheetRow $row): int => $row->line, $rows));
        self::assertSame(
            ['remise-am-bach-zuerich', 'seehof-muster', 'gutshof-erfunden-bern', 'villa-ohne-ja-luzern', 'halle-beispiel-basel'],
            array_map(static fn (VenueSheetRow $row): string => $row->key(), $rows),
        );
        self::assertSame([[], [], [], [
            'row 5: consent_on: the date of the yes (YYYY-MM-DD) is required',
            'row 5: consent_from: the address the yes came from is required',
        ], []], array_map($this->sheet()->check(...), $rows));

        $owner = $rows[0]->toDocument();
        self::assertEquals((object) [
            'name' => 'Remise am Bach',
            'status' => 'unpublished',
            'price_level' => 'medium',
            'capacity' => (object) ['min' => 20, 'max' => 120, 'seated' => 80, 'standing' => 120],
            'address' => (object) ['street' => 'Bachweg 3', 'zip' => '8001', 'town' => 'Zürich', 'canton' => 'zh'],
            'geo' => (object) ['lat' => 47.37, 'lng' => 8.54],
            'contact' => (object) ['phone' => '+41441234567', 'inquiry_email' => 'anfragen@remise-am-bach.example', 'website' => 'https://remise-am-bach.example', 'contact_person' => 'Anna Beispiel'],
            'terms' => (object) ['suitable_for' => ['corporate_event', 'seminar', 'apero'], 'building' => ['event_venue']],
            'main_terms' => (object) ['building' => 'event_venue'],
            'translations' => (object) ['de' => (object) ['summary' => 'Fiktive Remise am Bach für Firmenanlässe.', 'description' => 'Eine erfundene Remise mit Garten und Saal.']],
        ], $owner);

        $factsOnly = $rows[1]->toDocument();
        self::assertEquals((object) [
            'name' => 'Seehof Muster',
            'status' => 'unpublished',
            'capacity' => (object) ['max' => 60],
            'address' => (object) ['street' => 'Seestrasse 10', 'zip' => '8712', 'town' => 'Stäfa'],
            'terms' => (object) ['suitable_for' => ['team_event']],
        ], $factsOnly);
        self::assertObjectNotHasProperty('translations', $rows[4]->toDocument(), 'a crawled row carries no text');
    }

    public function test_a_row_breaking_the_rules_names_each_problem(): void
    {
        $row = new VenueSheetRow(7, [
            'source' => 'website', 'key' => 'Bad Key', 'name' => 'Ort', 'capacity_max' => 'viele', 'description' => 'Lang.',
            'consent_on' => '2026-11-01', 'consent_from' => 'a@b.example', 'event_types' => 'corporate_event|dance',
        ]);

        $errors = $this->sheet()->check($row);

        foreach ([
            'row 7: source must be one of owner, hochzeitslocation, eventlokale',
            'row 7: key: lower-case letters, digits and single hyphens, at most 100 characters',
            'row 7: summary: needed when description or region is written',
            'row 7: consent_on, consent_from: only for source hochzeitslocation',
            'row 7: /capacity/max:',
            'row 7: /terms/suitable_for/1: "dance" is not an allowed value',
        ] as $expected) {
            self::assertNotEmpty(array_filter($errors, static fn (string $error): bool => str_starts_with($error, $expected)), $expected."\n".implode("\n", $errors));
        }
    }

    public function test_a_text_is_refused_unless_the_row_is_the_owners(): void
    {
        $facts = ['name' => 'Gutshof Erfunden', 'town' => 'Bern', 'capacity_max' => '80', 'summary' => 'Ein erfundener Text.', 'description' => 'Noch ein erfundener Text.'];
        $consent = ['consent_on' => '2026-10-12', 'consent_from' => 'info@gutshof-erfunden.example'];

        self::assertSame([
            'row 3: summary: only for source owner, a text is never copied',
            'row 3: description: only for source owner, a text is never copied',
        ], $this->sheet()->check(new VenueSheetRow(3, ['source' => 'hochzeitslocation', ...$facts, ...$consent])));
        self::assertSame(
            ['row 4: summary: only for source owner, a text is never copied'],
            $this->sheet()->check(new VenueSheetRow(4, ['source' => 'eventlokale', ...$facts, 'description' => ''])),
        );
        self::assertSame([], $this->sheet()->check(new VenueSheetRow(5, ['source' => 'owner', ...$facts])));
    }

    public function test_a_yes_from_the_future_is_refused(): void
    {
        $row = new VenueSheetRow(2, ['source' => 'hochzeitslocation', 'name' => 'Gutshof Erfunden', 'town' => 'Bern', 'capacity_max' => '80', 'consent_on' => '2026-12-01', 'consent_from' => 'info@gutshof-erfunden.example']);

        self::assertSame(['row 2: consent_on: lies in the future'], $this->sheet()->check($row));
    }

    public function test_a_semicolon_sheet_with_a_byte_order_mark_reads_the_same(): void
    {
        $lines = file(self::FIXTURE, \FILE_IGNORE_NEW_LINES) ?: [];
        $file = $this->directory.'/sheet.csv';
        $handle = fopen($file, 'w');
        \assert(false !== $handle);
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($lines as $line) {
            fputcsv($handle, str_getcsv($line, ',', '"', ''), ';', '"', '');
        }
        fclose($handle);

        self::assertEquals(
            array_map(static fn (VenueSheetRow $row): \stdClass => $row->toDocument(), VenueSheet::read(self::FIXTURE)),
            array_map(static fn (VenueSheetRow $row): \stdClass => $row->toDocument(), VenueSheet::read($file)),
        );
    }

    public function test_a_file_with_a_missing_or_unknown_column_is_refused(): void
    {
        $file = $this->directory.'/sheet.csv';
        file_put_contents($file, implode(',', array_diff(VenueSheet::HEADER, ['consent_from']))."\n");
        try {
            VenueSheet::read($file);
            self::fail('missing column accepted');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('column consent_from is missing', $exception->getMessage());
        }

        file_put_contents($file, implode(',', [...VenueSheet::HEADER, 'rating'])."\n");
        $this->expectExceptionMessage('column rating is unknown');
        VenueSheet::read($file);
    }

    private function sheet(): VenueSheet
    {
        return new VenueSheet(new ContentSchema(\dirname(__DIR__, 4)), new MockClock('2026-11-08 10:00', 'Europe/Zurich'));
    }
}
