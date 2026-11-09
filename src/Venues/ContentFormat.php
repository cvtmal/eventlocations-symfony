<?php

declare(strict_types=1);

namespace App\Venues;

/**
 * Constants of the venue content format (#9, S8; docs/content-format.md).
 *
 * VERSION is part of every content hash: raising it makes the next import apply every
 * venue again (use it when the meaning of a field changes).
 *
 * 2 (event-vocabulary, F3): the `party` section and `translations.<locale>.party_note` are gone,
 * the term groups ceremony, dining, vendor and usage with them, `catering` is new, and the
 * codes of the other groups changed. A folder of version 1 that still names one of them fails
 * the schema, and every other folder is applied again.
 */
final class ContentFormat
{
    public const int VERSION = 2;

    /** Folder names (venue keys), room keys and --only. */
    public const string KEY_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    public const int KEY_MAX_LENGTH = 100;

    /** Relative to the project directory. */
    public const string SCHEMA_FILE = 'docs/content-format.schema.json';

    public static function isKey(string $key): bool
    {
        return \strlen($key) <= self::KEY_MAX_LENGTH && 1 === preg_match(self::KEY_PATTERN, $key);
    }

    /**
     * Where an original (photo or PDF) is stored under MEDIA_ROOT: content-addressed per
     * venue, so an unchanged file keeps its path whatever its name in the folder.
     */
    public static function originalPath(string $venueKey, string $sha256, string $extension): string
    {
        $extension = strtolower($extension);

        return \sprintf('%s/%s.%s', VenueOriginals::contentDirectory($venueKey), $sha256, 'jpeg' === $extension ? 'jpg' : $extension);
    }
}
