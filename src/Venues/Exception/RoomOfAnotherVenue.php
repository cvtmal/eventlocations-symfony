<?php

declare(strict_types=1);

namespace App\Venues\Exception;

use App\Shared\Exception\DomainException;

/**
 * A room photo must show a room of the photo's own venue. The database cannot check it
 * (a composite FK would make venue_id part of two associations), so VenuePhoto does.
 */
final class RoomOfAnotherVenue extends DomainException
{
    public static function forPhoto(string $roomId, string $venueId): self
    {
        return new self(\sprintf('Room "%s" does not belong to venue "%s".', $roomId, $venueId));
    }
}
