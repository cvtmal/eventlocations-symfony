<?php

declare(strict_types=1);

namespace App\Venues;

/**
 * Where a venue's originals live under MEDIA_ROOT (S9): the import's under `content/<venue key>/`
 * (ContentFormat::originalPath), uploads from the CMS and /konto under `cms/<venue number>/`
 * (AddVenuePhoto, AddVenueDocument). Each directory belongs to one venue, so deleting both deletes
 * every original it brought (F14: a withdrawn consent).
 *
 * A venue key that is no content key (ContentFormat::isKey) throws: '' would name all of `content/`, '../x' another
 * directory, and the withdrawal deletes what this names.
 */
final class VenueOriginals
{
    /**
     * @throws \InvalidArgumentException when the key is no content key (the import and the database never hold one)
     */
    public static function contentDirectory(string $venueKey): string
    {
        if (!ContentFormat::isKey($venueKey)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is no content key.', $venueKey));
        }

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
