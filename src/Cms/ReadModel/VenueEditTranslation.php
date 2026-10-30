<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

/**
 * One `venue_translations` row as the Texte, Adresse (region) and Hochzeit cards load it, verbatim.
 */
final readonly class VenueEditTranslation
{
    public function __construct(
        public string $locale,
        public string $slug,
        public string $summary,
        public ?string $description,
        public ?string $metaDescription,
        public ?string $parkingText,
        public ?string $region,
        public ?string $ambience,
        public ?string $capacityNote,
        public ?string $partyNote,
        public ?string $openingHours,
    ) {
    }
}
