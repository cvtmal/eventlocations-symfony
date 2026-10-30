<?php

declare(strict_types=1);

namespace App\Factory;

use App\Venues\Entity\Room;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\Seating;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Position defaults to 1; pass `position` for further rooms of the same venue.
 *
 * @extends PersistentObjectFactory<Room>
 */
final class RoomFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Room::class;
    }

    public function withSeating(Seating $seating, ?int $min, ?int $max): self
    {
        return $this->afterInstantiate(static fn (Room $room) => $room->offerSeating($seating, $min, $max));
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'venue' => VenueFactory::new(),
            'position' => 1,
            'capacityMin' => null,
            'capacityMax' => 80,
            'areaM2' => 100,
            'heightM' => '3.50',
            'floorMaterial' => self::faker()->randomElement([...FloorMaterial::cases(), null]),
        ];
    }
}
