<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues\Entity;

use App\Venues\Entity\Room;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\Seating;
use PHPUnit\Framework\TestCase;

final class RoomTest extends TestCase
{
    public function test_each_seating_type_has_its_own_range(): void
    {
        $room = new Room(VenueObjects::venue(), 1, 10, 200, 180, '3.50');

        foreach (Seating::cases() as $i => $seating) {
            self::assertNull($room->seatingMin($seating));
            self::assertNull($room->seatingMax($seating));
            $room->offerSeating($seating, 10 + $i, 100 + $i);
        }

        foreach (Seating::cases() as $i => $seating) {
            self::assertSame(10 + $i, $room->seatingMin($seating), $seating->value);
            self::assertSame(100 + $i, $room->seatingMax($seating), $seating->value);
        }

        $room->offerSeating(Seating::Apero, null, null);
        self::assertNull($room->seatingMax(Seating::Apero));
        self::assertSame(103, $room->seatingMax(Seating::Concert));
        self::assertSame(3.5, $room->heightM());
    }

    public function test_content_key_move_and_reshape(): void
    {
        self::assertNull((new Room(VenueObjects::venue(), 1))->contentKey());

        $room = new Room(VenueObjects::venue(), 1, contentKey: 'rittersaal');
        self::assertSame('rittersaal', $room->contentKey());
        $before = $room->updatedAt();
        usleep(1);

        $room->moveTo(3);
        $room->reshape(20, 120, 180, '6.50', FloorMaterial::Parquet);

        self::assertSame(3, $room->position());
        self::assertSame([20, 120, 180, 6.5, FloorMaterial::Parquet], [$room->capacityMin(), $room->capacityMax(), $room->areaM2(), $room->heightM(), $room->floorMaterial()]);
        self::assertGreaterThan($before, $room->updatedAt());

        $room->reshape(null, null, null, null, null);
        self::assertSame([null, null, null, null, null], [$room->capacityMin(), $room->capacityMax(), $room->areaM2(), $room->heightM(), $room->floorMaterial()]);
    }
}
