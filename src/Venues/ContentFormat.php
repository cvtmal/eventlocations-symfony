<?php

declare(strict_types=1);

namespace App\Venues;

/**
 * Constants of the venue content format (#9, S8; docs/content-format.md).
 *
 * VERSION is part of every content hash: raising it makes the next import apply every
 * venue again (use it when the meaning of a field changes).
 */
final class ContentFormat
{
    public const int VERSION = 1;

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

        return \sprintf('content/%s/%s.%s', $venueKey, $sha256, 'jpeg' === $extension ? 'jpg' : $extension);
    }
}
