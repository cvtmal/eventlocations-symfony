<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues\Entity;

use App\Venues\Entity\Room;
use App\Venues\Entity\VenuePhoto;
use App\Venues\Exception\RoomOfAnotherVenue;
use PHPUnit\Framework\TestCase;

final class VenuePhotoTest extends TestCase
{
    public function test_rejects_a_room_of_another_venue(): void
    {
        $venue = VenueObjects::venue();
        $otherRoom = new Room(VenueObjects::venue(), 1);

        $this->expectException(RoomOfAnotherVenue::class);

        new VenuePhoto($venue, $otherRoom, 1, 'demo/photo.jpg');
    }

    public function test_accepts_its_own_room_and_no_room(): void
    {
        $venue = VenueObjects::venue();
        $room = new Room($venue, 1);

        self::assertSame($room, (new VenuePhoto($venue, $room, 1, 'demo/room.jpg'))->room());
        self::assertNull((new VenuePhoto($venue, null, 1, 'demo/venue.jpg'))->room());
    }
}
