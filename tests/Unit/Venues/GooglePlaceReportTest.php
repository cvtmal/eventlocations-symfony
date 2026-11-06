<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\GooglePlaceFinder;
use App\Venues\GooglePlaceReport;
use App\Venues\VenueCoordinateFiller;
use PHPUnit\Framework\TestCase;

final class GooglePlaceReportTest extends TestCase
{
    /** #53's header, before #57 replaced it. */
    private const array OLD_HEADER = ['number', 'name', 'published', 'status', 'query', 'place_id', 'matched_name', 'name_matches', 'address', 'distance_km', 'apply'];

    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/google-places-report-'.bin2hex(random_bytes(4)).'.csv';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function test_only_a_yes_applies_in_any_case_and_with_spaces(): void
    {
        file_put_contents($this->file, VenueCoordinateFiller::csv([
            GooglePlaceFinder::HEADER,
            self::row(7, 'confident', ' YES ', ' ChIJScheune0001 '),
            self::row(8, 'doubtful', '', 'ChIJRemise00002'),
            self::row(9, 'doubtful', 'ja', 'ChIJHof00000003'),
        ])."\n");

        self::assertSame([
            ['number' => 7, 'placeId' => 'ChIJScheune0001', 'apply' => true, 'confidence' => 'confident'],
            ['number' => 8, 'placeId' => 'ChIJRemise00002', 'apply' => false, 'confidence' => 'doubtful'],
            ['number' => 9, 'placeId' => 'ChIJHof00000003', 'apply' => false, 'confidence' => 'doubtful'],
        ], GooglePlaceReport::read($this->file));
    }

    public function test_the_new_report_returns_the_confidence(): void
    {
        file_put_contents($this->file, VenueCoordinateFiller::csv([
            GooglePlaceFinder::HEADER,
            self::row(7, 'confident', 'yes', 'ChIJScheune0001'),
            self::row(8, 'not found', '', ''),
            self::row(9, 'failed', '', ''),
        ]));

        self::assertSame(['confident', 'not found', 'failed'], array_column(GooglePlaceReport::read($this->file), 'confidence'));
    }

    public function test_a_report_of_53_is_still_read(): void
    {
        file_put_contents($this->file, VenueCoordinateFiller::csv([
            self::OLD_HEADER,
            ['7', 'Scheune', 'yes', 'found', 'q', ' ChIJScheune0001 ', 'Scheune', 'yes', 'a', '0.2', ' YES '],
            ['8', 'Remise', 'yes', 'found', 'q', 'ChIJRemise00002', 'Post', 'NO', 'a', '9.0', ''],
        ]));

        self::assertSame([
            ['number' => 7, 'placeId' => 'ChIJScheune0001', 'apply' => true, 'confidence' => null],
            ['number' => 8, 'placeId' => 'ChIJRemise00002', 'apply' => false, 'confidence' => null],
        ], GooglePlaceReport::read($this->file));
    }

    public function test_columns_are_found_by_name_in_any_order(): void
    {
        file_put_contents($this->file, VenueCoordinateFiller::csv([
            ['apply', 'note', 'place_id', 'number'],
            ['yes', 'von Hand', 'ChIJScheune0001', '7'],
        ]));

        self::assertSame([['number' => 7, 'placeId' => 'ChIJScheune0001', 'apply' => true, 'confidence' => null]], GooglePlaceReport::read($this->file));
    }

    public function test_a_header_without_apply_is_refused(): void
    {
        file_put_contents($this->file, "number,name,place_id\n7,Scheune,ChIJScheune0001\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not a place ID report');

        GooglePlaceReport::read($this->file);
    }

    public function test_a_byte_order_mark_is_accepted(): void
    {
        file_put_contents($this->file, "\u{FEFF}".VenueCoordinateFiller::csv([GooglePlaceFinder::HEADER, self::row(7, 'confident', 'yes', 'ChIJScheune0001')]));

        self::assertSame([['number' => 7, 'placeId' => 'ChIJScheune0001', 'apply' => true, 'confidence' => 'confident']], GooglePlaceReport::read($this->file));
    }

    public function test_a_missing_file_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Run app:venues:find-google-places first.');

        GooglePlaceReport::read($this->file);
    }

    public function test_another_header_is_refused(): void
    {
        file_put_contents($this->file, "folder,name,query,status,matched_name,name_matches,address,lat,lng\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not a place ID report');

        GooglePlaceReport::read($this->file);
    }

    /**
     * A row of #57's report, built by column name.
     *
     * @return list<string>
     */
    private static function row(int $number, string $confidence, string $apply, string $placeId): array
    {
        $row = [
            'number' => (string) $number, 'name' => 'Scheune', 'published' => 'yes', 'confidence' => $confidence, 'reason' => 'Grund',
            'apply' => $apply, 'maps_url' => 'https://www.google.com/maps', 'place_id' => $placeId, 'google_name' => 'Scheune',
            'google_address' => 'Adresse', 'distance_km' => '', 'query' => 'Scheune Schweiz',
        ];

        return array_map(static fn (string $column): string => $row[$column], GooglePlaceFinder::HEADER);
    }
}
