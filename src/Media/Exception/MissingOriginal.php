<?php

declare(strict_types=1);

namespace App\Media\Exception;

use App\Shared\Exception\DomainException;

/**
 * An original (photo or PDF) that a row points at is not on disk under MEDIA_ROOT.
 */
final class MissingOriginal extends DomainException
{
    public static function forPath(string $absolutePath): self
    {
        return new self(\sprintf('The original "%s" does not exist or is not readable.', $absolutePath));
    }
}
