<?php

declare(strict_types=1);

namespace App\Media\Exception;

use App\Shared\Exception\DomainException;

/**
 * A stored media path is not a plain relative path inside MEDIA_ROOT.
 */
final class InvalidMediaPath extends DomainException
{
    public static function forPath(string $relativePath): self
    {
        return new self(\sprintf('The media path "%s" must be relative to MEDIA_ROOT, without "..", backslashes or NUL bytes.', str_replace("\0", '\0', $relativePath)));
    }
}
