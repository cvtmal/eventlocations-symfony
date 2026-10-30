<?php

declare(strict_types=1);

namespace App\Shared\Uid;

use Symfony\Component\Uid\Uuid;

/**
 * The single place where entity identifiers are created.
 */
final class EntityId
{
    private function __construct()
    {
    }

    /**
     * Returns a time-ordered UUID v7 as an RFC 4122 string.
     */
    public static function generate(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
