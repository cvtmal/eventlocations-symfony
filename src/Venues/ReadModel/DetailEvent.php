<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\Term;

/**
 * The event facts of the section "Angaben zur Location" (DP-17, S14; event-vocabulary, F3). NULL
 * or an empty list means unknown, and the page shows nothing for it.
 */
final readonly class DetailEvent
{
    /**
     * @param list<Term> $amenities in position order, like the other lists
     * @param list<Term> $catering
     * @param list<Term> $languages
     */
    public function __construct(
        public ?PriceLevel $priceLevel,
        public ?string $ambience,
        public ?int $capacitySeated,
        public ?int $capacityStanding,
        public ?string $capacityNote,
        public ?string $openingHours,
        public array $amenities,
        public array $catering,
        public array $languages,
    ) {
    }

    /**
     * Whether the section has anything to show.
     */
    public function hasAny(): bool
    {
        foreach (get_object_vars($this) as $fact) {
            if (null !== $fact && [] !== $fact) {
                return true;
            }
        }

        return false;
    }
}
