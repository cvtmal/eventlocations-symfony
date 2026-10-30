<?php

declare(strict_types=1);

namespace App\Media\Exception;

use App\Shared\Exception\DomainException;

/**
 * gd cannot decode the original (not an image, or a format gd was built without).
 */
final class UnreadableImage extends DomainException
{
    public static function forFile(string $absolutePath): self
    {
        return new self(\sprintf('The file "%s" is not an image gd can decode.', $absolutePath));
    }
}
