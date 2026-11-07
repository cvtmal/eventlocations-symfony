<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues\Entity;

use App\Venues\Entity\Room;
use App\Venues\Entity\RoomTranslation;
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

    public function test_the_layouts_give_the_room_its_guest_range(): void
    {
        $room = new Room(VenueObjects::venue(), 1, 10, 400);

        $room->offerSeating(Seating::Banquet, 40, 150);
        self::assertSame([40, 150], [$room->capacityMin(), $room->capacityMax()]);

        $room->offerSeating(Seating::Apero, 30, 220);
        $room->offerSeating(Seating::UShape, 5, null);
        $room->offerSeating(Seating::Block, null, 24);
        self::assertSame([30, 220], [$room->capacityMin(), $room->capacityMax()], 'greatest max, least min of the layouts with a max');

        $room->reshape(1, 999, null, null, null);
        self::assertSame([30, 220], [$room->capacityMin(), $room->capacityMax()], 'the stated range gives way');

        foreach (Seating::cases() as $seating) {
            $room->offerSeating($seating, null, null);
        }
        $room->reshape(1, 999, null, null, null);
        self::assertSame([1, 999], [$room->capacityMin(), $room->capacityMax()], 'without layouts the stated range stays');
    }

    public function test_layout_range(): void
    {
        self::assertNull(Seating::guestRange([]));
        self::assertNull(Seating::guestRange([['min' => 20, 'max' => null]]));
        self::assertSame(['min' => null, 'max' => 80], Seating::guestRange([['min' => null, 'max' => 80], ['min' => 5, 'max' => null]]));
        self::assertSame(['min' => 12, 'max' => 300], Seating::guestRange([['min' => 50, 'max' => 300], ['min' => 12, 'max' => 40], ['min' => null, 'max' => 90]]));
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

    public function test_translation_revise_replaces_name_and_description(): void
    {
        $translation = new RoomTranslation(new Room(VenueObjects::venue(), 1), 'de', 'Rittersaal', 'Alt.');
        $before = $translation->updatedAt();
        usleep(1);

        $translation->revise('Grosser Saal', null);

        self::assertSame(['Grosser Saal', null, 'de'], [$translation->name(), $translation->description(), $translation->locale()]);
        self::assertGreaterThan($before, $translation->updatedAt());
    }
}
