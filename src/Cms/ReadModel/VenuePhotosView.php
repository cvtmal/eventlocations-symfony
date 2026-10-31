<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

/**
 * A venue's photos and rooms for the photo manager (#25): the gallery first, then each room's
 * photos in room order, each by position.
 */
final readonly class VenuePhotosView
{
    /**
     * @param list<ManagedPhoto> $photos
     * @param list<RoomOption>   $rooms  by position
     */
    public function __construct(
        public array $photos,
        public array $rooms,
    ) {
    }

    /**
     * @return list<array{id: string, roomId: ?string, position: int, thumbnailUrl: string, width: int, height: int, dominantColor: string, widths: list<int>, formats: list<string>, alts: array<string, string>, token: string}>
     */
    public function photosArray(): array
    {
        return array_map(static fn (ManagedPhoto $photo): array => $photo->toArray(), $this->photos);
    }

    /**
     * @return list<array{id: string, name: string, position: int}>
     */
    public function roomsArray(): array
    {
        return array_map(static fn (RoomOption $room): array => $room->toArray(), $this->rooms);
    }
}
