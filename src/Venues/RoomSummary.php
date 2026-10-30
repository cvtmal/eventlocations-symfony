<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\Entity\Room;
use App\Venues\Entity\Venue;

/**
 * The venue's room facts the filters read (FI-4) from its rooms: their number, the largest
 * area and the tallest height ('%.2f'), each null when no room states it.
 *
 * Callers: the content import (ImportVenueContentHandler) after it wrote the rooms, and
 * #25's room commands, each with the venue's rooms after its change.
 */
final class RoomSummary
{
    /**
     * @param list<Room> $rooms every room of the venue
     */
    public static function apply(Venue $venue, array $rooms): void
    {
        $areas = array_values(array_filter(array_map(static fn (Room $room): ?int => $room->areaM2(), $rooms), static fn (?int $area): bool => null !== $area));
        $heights = array_values(array_filter(array_map(static fn (Room $room): ?float => $room->heightM(), $rooms), static fn (?float $height): bool => null !== $height));

        $venue->summariseRooms(
            \count($rooms),
            [] === $areas ? null : max($areas),
            [] === $heights ? null : \sprintf('%.2f', max($heights)),
        );
    }
}
