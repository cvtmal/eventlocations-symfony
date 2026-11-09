<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\EventlokaleCache;
use App\Crm\EventlokaleCsv;
use App\Tests\Support\Crm\EventlokalePayloads;
use PHPUnit\Framework\TestCase;

final class EventlokaleCsvTest extends TestCase
{
    private const string BASE = 'https://www.eventlokale.ch';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/eventlokale-csv-'.bin2hex(random_bytes(4));
        $cache = new EventlokaleCache($this->root);
        $cache->writeTaxonomies(EventlokalePayloads::taxonomies());
        // Out of id order on purpose: the CSV sorts.
        $cache->writeIndex(370, [
            EventlokalePayloads::listItem(3),
            EventlokalePayloads::listItem(1),
            EventlokalePayloads::listItem(2),
        ], 3, new \DateTimeImmutable('2026-11-03 10:00:00'));
        $cache->writeIndex(371, [EventlokalePayloads::listItem(2)], 1, new \DateTimeImmutable('2026-11-03 10:00:00'));
        $cache->writeDetail(1, EventlokalePayloads::detail(1));
        $cache->markGone(3);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->root.'/eventlokale/venues/*') ?: []);
        array_map('unlink', glob($this->root.'/eventlokale/*.json') ?: []);
        array_map('unlink', glob($this->root.'/owner/*') ?: []);
        @rmdir($this->root.'/eventlokale/venues');
        @rmdir($this->root.'/eventlokale');
        @rmdir($this->root.'/owner');
        @rmdir($this->root);
    }

    public function test_the_file_starts_with_a_bom_and_the_header(): void
    {
        $result = (new EventlokaleCsv())->write($this->root);

        $contents = (string) file_get_contents($this->root.'/'.EventlokaleCsv::FILE);
        self::assertStringStartsWith("\xEF\xBB\xBF", $contents);
        self::assertSame(EventlokaleCsv::HEADER, $this->read()[0]);
        self::assertSame(3, $result['rows']);
    }

    public function test_rows_are_sorted_by_id(): void
    {
        (new EventlokaleCsv())->write($this->root);

        self::assertSame(['1', '2', '3'], array_column($this->rows(), 'eventlokale_id'));
        self::assertSame(['370', '370|371', '370'], array_column($this->rows(), 'events'), 'the categories that list each venue');
    }

    public function test_a_suppressed_address_loses_the_person_and_contact_cells_but_the_venue_stays(): void
    {
        (new EventlokaleCsv())->write($this->root, ['info@example.test']);

        $row = $this->rows()[0];
        self::assertSame('Testschloss Musterberg am See 1', $row['name']);
        self::assertSame(['Muster Events GmbH', 'www.example.test'], [$row['contact_company'], $row['website']]);
        foreach (['contact_salutation', 'contact_first_name', 'contact_last_name', 'contact_function', 'phone', 'mobile', 'email'] as $column) {
            self::assertSame('', $row[$column], $column);
        }
    }

    public function test_a_row_with_a_detail_carries_contact_rooms_photos_and_faq(): void
    {
        (new EventlokaleCsv())->write($this->root);

        $row = $this->rows()[0];
        self::assertSame('ok', $row['detail_status']);
        self::assertSame('Testschloss Musterberg am See 1', $row['name']);
        self::assertSame(self::BASE.'/site/_eventlokale/1/Schweiz/1/testschloss-musterberg-1.html', $row['eventlokale_url']);
        self::assertSame(['Musterweg', '7a', '9999', 'Musterdorf', 'Musterkanton'], [$row['street'], $row['street_number'], $row['zip'], $row['place'], $row['region']]);
        self::assertSame(['12.5', '1.2', '3.4', 'Musterdorf Bahnhof'], [$row['distance_airport_km'], $row['distance_train_station_km'], $row['distance_highway_km'], $row['train_station_name']]);
        self::assertSame(
            ['Muster Events GmbH', 'Frau', 'Erika', 'Muster', 'Leitung Events', 'info@example.test', 'www.example.test'],
            [$row['contact_company'], $row['contact_salutation'], $row['contact_first_name'], $row['contact_last_name'], $row['contact_function'], $row['email'], $row['website']],
        );
        self::assertSame(
            'Rittersaal: Etage EG, 120 m², 4.5 m, Bankett 20-100, Apéro bis 150, Seminar 10-80, 10-150 Pers. | Turmzimmer: 40 m², bis 20 Pers.',
            $row['rooms'],
        );
        self::assertSame('3', $row['photo_count']);
        self::assertSame(self::BASE.'/storage/101/test-1.jpg', $row['cover_photo_url']);
        self::assertSame(
            self::BASE.'/storage/101/test-1.jpg | '.self::BASE.'/storage/102/test-2.jpg | '.self::BASE.'/storage/103/test-3.jpg',
            $row['photo_urls'],
        );
        self::assertSame(self::BASE.'/storage/201/test-menu.pdf', $row['document_urls']);
        self::assertSame('https://video.example.test/watch/1 | https://video.example.test/2 | https://vr.example.test/3', $row['video_urls']);
        self::assertSame(
            ['Genügend Parkplätze', 'ja', 'nein', 'ja', 'nein', '', '4'],
            [$row['parking'], $row['own_catering'], $row['free_parking'], $row['barrier_free'], $row['daylight'], $row['single_rooms'], $row['double_rooms']],
        );
        self::assertSame('Ein erfundener Text über das Testschloss.', $row['description']);
        self::assertSame('2026-01-02T03:04:05.000000Z', $row['eventlokale_updated_at']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+0[12]:00$/', $row['crawled_at']);
    }

    public function test_a_row_without_a_detail_takes_terms_and_photos_from_the_list(): void
    {
        (new EventlokaleCsv())->write($this->root);

        $row = $this->rows()[1];
        self::assertSame('missing', $row['detail_status']);
        self::assertSame('Testschloss Musterberg 2', $row['name']);
        self::assertSame(
            ['Schloss', 'Am See', 'Hochzeit', 'Garten', 'Beamer', 'Stehtische', 'Wald', 'Rollstuhlgängig', 'Erfundenes Highlight'],
            [$row['building_types'], $row['settings'], $row['suitable_for'], $row['infrastructure'], $row['technology'], $row['furniture'], $row['surroundings'], $row['access'], $row['highlights']],
        );
        self::assertSame('2', $row['photo_count']);
        self::assertSame(self::BASE.'/storage/11/test-11.jpg | '.self::BASE.'/storage/12/test-12.jpg', $row['photo_urls']);
        self::assertSame(['', '', '', '', ''], [$row['contact_company'], $row['email'], $row['phone'], $row['street'], $row['crawled_at']]);
        self::assertSame(['10', '150', '120', '4.5', '2'], [$row['min_capacity'], $row['max_capacity'], $row['max_area_m2'], $row['max_height_m'], $row['room_count']]);
    }

    public function test_a_gone_venue_is_marked(): void
    {
        (new EventlokaleCsv())->write($this->root);

        self::assertSame('gone', $this->rows()[2]['detail_status']);
    }

    public function test_entities_and_tags_are_cleaned_and_whitespace_collapsed(): void
    {
        (new EventlokaleCsv())->write($this->root);

        self::assertSame('Ein erfundenes Schloss & Garten mit Seeblick.', $this->rows()[0]['summary']);
    }

    public function test_a_phone_starting_with_plus_is_guarded_against_formulas(): void
    {
        (new EventlokaleCsv())->write($this->root);

        self::assertSame("'+41 00 000 00 00", $this->rows()[0]['phone']);
        self::assertSame('000 000 00 00', $this->rows()[0]['mobile']);
    }

    public function test_reviewer_comments_never_reach_the_csv(): void
    {
        (new EventlokaleCsv())->write($this->root);

        self::assertStringNotContainsString(EventlokalePayloads::SECRET_COMMENT, (string) file_get_contents($this->root.'/'.EventlokaleCsv::FILE));
        self::assertStringNotContainsString('Erfundene Person', (string) file_get_contents($this->root.'/'.EventlokaleCsv::FILE));
    }

    public function test_it_names_the_columns_empty_in_every_row(): void
    {
        $result = (new EventlokaleCsv())->write($this->root);

        self::assertSame(['single_rooms', 'award'], $result['emptyColumns']);
    }

    /**
     * @return list<array<string, string>> the data rows keyed by header
     */
    private function rows(): array
    {
        $lines = $this->read();
        $header = array_shift($lines) ?? [];

        return array_map(static fn (array $line): array => array_combine($header, $line), $lines);
    }

    /**
     * @return list<list<string>>
     */
    private function read(): array
    {
        $handle = fopen($this->root.'/'.EventlokaleCsv::FILE, 'r');
        self::assertIsResource($handle);
        self::assertSame("\xEF\xBB\xBF", fread($handle, 3));
        $lines = [];
        while (false !== $line = fgetcsv($handle, null, ';', '"', '')) {
            $lines[] = array_map(static fn (?string $cell): string => (string) $cell, $line);
        }
        fclose($handle);

        return $lines;
    }
}
