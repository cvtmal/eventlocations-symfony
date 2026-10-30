<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Tests\Unit\Venues\Entity\VenueObjects;
use App\Venues\Entity\Room;
use App\Venues\RoomSummary;
use PHPUnit\Framework\TestCase;

final class RoomSummaryTest extends TestCase
{
    public function test_no_rooms_count_zero_and_no_maxima(): void
    {
        $venue = VenueObjects::venue();

        RoomSummary::apply($venue, []);

        self::assertSame([0, null, null], [$venue->roomCount(), $venue->largestRoomM2(), $venue->maxRoomHeightM()]);
    }

    public function test_the_maxima_skip_rooms_that_do_not_state_them(): void
    {
        $venue = VenueObjects::venue();
        $rooms = [
            new Room($venue, 1, areaM2: 120, heightM: '4.20'),
            new Room($venue, 2, areaM2: null, heightM: '6.50'),
            new Room($venue, 3, areaM2: 180, heightM: null),
        ];

        RoomSummary::apply($venue, $rooms);

        self::assertSame([3, 180, 6.5], [$venue->roomCount(), $venue->largestRoomM2(), $venue->maxRoomHeightM()]);
    }

    public function test_rooms_without_area_or_height_leave_the_maxima_null(): void
    {
        $venue = VenueObjects::venue();

        RoomSummary::apply($venue, [new Room($venue, 1), new Room($venue, 2)]);

        self::assertSame([2, null, null], [$venue->roomCount(), $venue->largestRoomM2(), $venue->maxRoomHeightM()]);
    }
}
