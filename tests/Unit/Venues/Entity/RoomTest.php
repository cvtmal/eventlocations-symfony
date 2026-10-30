<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues\Entity;

use App\Venues\Entity\Room;
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
}
