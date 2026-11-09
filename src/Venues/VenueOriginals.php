<?php

declare(strict_types=1);

namespace App\Venues;

/**
 * Where a venue's originals live under MEDIA_ROOT (S9): the import's under `content/<venue key>/`
 * (ContentFormat::originalPath), uploads from the CMS and /konto under `cms/<venue number>/`
 * (AddVenuePhoto, AddVenueDocument). Each directory belongs to one venue, so deleting both deletes
 * every original it brought (F14: a withdrawn consent).
 */
final class VenueOriginals
{
    public static function contentDirectory(string $venueKey): string
    {
        return 'content/'.$venueKey;
    }

    public static function uploadDirectory(int $venueNumber): string
    {
        return \sprintf('cms/%d', $venueNumber);
    }

    /**
     * @return list<string> relative to MEDIA_ROOT
     */
    public static function directories(int $venueNumber, ?string $venueKey): array
    {
        return null === $venueKey
            ? [self::uploadDirectory($venueNumber)]
            : [self::contentDirectory($venueKey), self::uploadDirectory($venueNumber)];
    }
}
