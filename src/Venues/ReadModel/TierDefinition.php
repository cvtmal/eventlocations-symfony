<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Venues\ValueObject\TierLabel;

/**
 * A tier as the staff screen "Tarife" shows and edits it (F12): every column of the row, plus how many venues are on
 * it. What a venue may do comes from `EntitlementResolver`, not from here.
 */
final readonly class TierDefinition
{
    public function __construct(
        public string $code,
        public string $name,
        public int $priceRappen,
        public int $rankWeight,
        public int $photosMax,
        public int $roomsMax,
        public int $videosMax,
        public int $documentsMax,
        public bool $contactVisible,
        public bool $lastMinuteAllowed,
        public bool $detailedReport,
        public ?int $eventTypesMax,
        public int $venueCount,
    ) {
    }

    public function label(): TierLabel
    {
        return TierLabel::of($this->code, $this->name, $this->rankWeight);
    }
}
