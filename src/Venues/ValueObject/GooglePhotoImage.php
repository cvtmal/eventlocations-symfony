<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * The bytes of one Google Places photo on their way from Google to the visitor (D12): held in memory for the one
 * response that passes them on, never written anywhere.
 */
final readonly class GooglePhotoImage
{
    public function __construct(
        public string $bytes,
        public string $contentType,
    ) {
    }
}
