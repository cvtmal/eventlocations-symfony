<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues\Entity;

use App\Venues\ValueObject\RatingLabel;
use App\Venues\ValueObject\VenueStatus;
use PHPUnit\Framework\TestCase;

final class VenueTest extends TestCase
{
    public function test_canton_is_taken_from_the_town(): void
    {
        $zurich = VenueObjects::canton('zh');
        $venue = VenueObjects::venue(VenueObjects::town($zurich));

        self::assertSame($zurich, $venue->canton());
    }

    public function test_relocate_moves_the_canton_with_the_town(): void
    {
        $venue = VenueObjects::venue();
        $bern = VenueObjects::canton('be');
        $thun = VenueObjects::town($bern, 'Thun');

        $venue->relocate($thun, '3600', null, 46.75, 7.62);

        self::assertSame($thun, $venue->town());
        self::assertSame($bern, $venue->canton());
        self::assertSame('3600', $venue->zip());
        self::assertNull($venue->street());
    }

    public function test_unrated_venue_has_no_label(): void
    {
        $venue = VenueObjects::venue();
        $venue->rate(null, null);

        self::assertNull($venue->ratingScore());
        self::assertNull($venue->ratingCount());
        self::assertNull($venue->ratingLabel());
    }

    public function test_rating_gives_the_label_of_its_score(): void
    {
        $venue = VenueObjects::venue();
        $venue->rate('8.4', 12);

        self::assertSame(8.4, $venue->ratingScore());
        self::assertSame(12, $venue->ratingCount());
        self::assertSame(RatingLabel::Excellent, $venue->ratingLabel());
    }

    public function test_decimal_columns_read_back_as_floats(): void
    {
        $venue = VenueObjects::venue();
        $venue->changeDistances('1.5', 'Stäfa', '12.0', null, null, null);
        $venue->summariseRooms(3, 240, '4.20');

        self::assertSame(1.5, $venue->distanceStationKm());
        self::assertSame(12.0, $venue->distanceHighwayKm());
        self::assertNull($venue->distanceAirportKm());
        self::assertSame(4.2, $venue->maxRoomHeightM());
        self::assertSame(3, $venue->roomCount());
    }

    public function test_publish_and_unpublish(): void
    {
        $venue = VenueObjects::venue();
        $venue->unpublish();
        self::assertSame(VenueStatus::Unpublished, $venue->status());
        self::assertFalse($venue->isPublished());

        $venue->publish();
        self::assertTrue($venue->isPublished());
    }
}
