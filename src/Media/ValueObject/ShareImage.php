<?php

declare(strict_types=1);

namespace App\Media\ValueObject;

/**
 * The JPEG variant of a photo that social cards, JSON-LD and the sitemap point at. The URL is a
 * path; the caller makes it absolute.
 */
final readonly class ShareImage
{
    public function __construct(
        public string $url,
        public int $width,
        public int $height,
    ) {
    }
}
