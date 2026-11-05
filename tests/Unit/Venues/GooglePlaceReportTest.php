<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\GooglePlaceFinder;
use App\Venues\GooglePlaceReport;
use App\Venues\VenueCoordinateFiller;
use PHPUnit\Framework\TestCase;

final class GooglePlaceReportTest extends TestCase
{
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
            ['7', 'Scheune', 'yes', 'found', 'q', ' ChIJScheune0001 ', 'Scheune', 'yes', 'a', '0.2', ' YES '],
            ['8', 'Remise', 'yes', 'found', 'q', 'ChIJRemise00002', 'Post', 'NO', 'a', '9.0', ''],
            ['9', 'Hof', 'no', 'found', 'q', 'ChIJHof00000003', 'Hof', 'yes', 'a', '', 'ja'],
        ])."\n");

        self::assertSame([
            ['number' => 7, 'placeId' => 'ChIJScheune0001', 'apply' => true],
            ['number' => 8, 'placeId' => 'ChIJRemise00002', 'apply' => false],
            ['number' => 9, 'placeId' => 'ChIJHof00000003', 'apply' => false],
        ], GooglePlaceReport::read($this->file));
    }

    public function test_a_byte_order_mark_is_accepted(): void
    {
        file_put_contents($this->file, "\u{FEFF}".VenueCoordinateFiller::csv([GooglePlaceFinder::HEADER, ['7', 'S', 'yes', 'found', 'q', 'ChIJScheune0001', 'S', 'yes', 'a', '', 'yes']]));

        self::assertSame([['number' => 7, 'placeId' => 'ChIJScheune0001', 'apply' => true]], GooglePlaceReport::read($this->file));
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
}
