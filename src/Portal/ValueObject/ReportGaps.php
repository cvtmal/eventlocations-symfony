<?php

declare(strict_types=1);

namespace App\Portal\ValueObject;

/**
 * What an entry still lacks, as far as the monthly report's suggestion (G10, `ReportSuggestion::for()`) asks.
 * `galleryPhotos` counts photos outside a room; `photosUsed` counts every photo, as the plan card does.
 */
final readonly class ReportGaps
{
    public function __construct(
        public bool $hasInquiryEmail,
        public int $galleryPhotos,
        public int $photosUsed,
        public int $photosMax,
        public bool $hasDescription,
        public int $rooms,
        public bool $hasSeatedOrStanding,
        public int $eventTypes,
        public bool $hasPriceLevel,
    ) {
    }
}
