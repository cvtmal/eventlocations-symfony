<?php

declare(strict_types=1);

namespace App\Media\ValueObject;

/**
 * The variant formats in source preference order (S9): AVIF, then WebP, then JPEG. The value
 * is the file extension and the token stored in venue_photos.variants.
 */
enum ImageFormat: string
{
    case Avif = 'avif';
    case Webp = 'webp';
    case Jpeg = 'jpg';

    public function mimeType(): string
    {
        return match ($this) {
            self::Avif => 'image/avif',
            self::Webp => 'image/webp',
            self::Jpeg => 'image/jpeg',
        };
    }
}
