<?php

declare(strict_types=1);

namespace App\Venues\ValueObject\Content;

/**
 * One row of the owner's CSV as the paths #21 owns in a venue.yaml (D7).
 */
final readonly class LegacyVenue
{
    /**
     * @param 1|2|3|4                                 $descriptionRule the rule of LegacyVenueText::description()
     * @param 'whole'|'sentence'|'word'|'ambience'|'' $summarySource
     * @param list<string>                            $notes           what the owner should look at; never a cell's value
     */
    public function __construct(
        public string $id8,
        public string $name,
        public int $capacityMax,
        public ?string $cantonCode,
        public ?string $region,
        public ?string $phone,
        public ?string $inquiryEmail,
        public ?string $website,
        public string $summary,
        public ?string $description,
        public int $descriptionRule,
        public string $summarySource,
        public array $notes,
    ) {
    }
}
