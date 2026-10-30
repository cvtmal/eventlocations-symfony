<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

/**
 * What a detail-page slug points at: a venue, its current slug in the requested locale, whether
 * the requested slug is that current one (else it is a `venue_slug_history` slug → 301) and
 * whether the venue is published (else 410). The number is what detail() takes; the name serves
 * the phone-reveal page.
 */
final readonly class SlugMatch
{
    public function __construct(
        public string $venueId,
        public int $number,
        public string $name,
        public string $currentSlug,
        public bool $isCurrent,
        public bool $isPublished,
    ) {
    }
}
