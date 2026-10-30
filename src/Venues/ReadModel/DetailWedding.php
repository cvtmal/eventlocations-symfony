<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Venues\ValueObject\PartyAllowed;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\Term;

/**
 * The owner facts of the section "Hochzeit" (DP-17, S14). NULL or an empty list means unknown,
 * and the page shows nothing for it. `curfewAt` is `HH:MM`.
 */
final readonly class DetailWedding
{
    /**
     * @param list<Term> $ceremony  in position order, like the other lists
     * @param list<Term> $amenities
     * @param list<Term> $dining
     * @param list<Term> $usage
     * @param list<Term> $vendors
     * @param list<Term> $languages
     */
    public function __construct(
        public ?PriceLevel $priceLevel,
        public ?string $ambience,
        public ?int $capacitySeated,
        public ?int $capacityStanding,
        public ?string $capacityNote,
        public ?PartyAllowed $party,
        public ?bool $hasCurfew,
        public ?string $curfewAt,
        public ?string $partyNote,
        public ?string $openingHours,
        public array $ceremony,
        public array $amenities,
        public array $dining,
        public array $usage,
        public array $vendors,
        public array $languages,
    ) {
    }

    /**
     * Whether the section has anything to show. `hasCurfew` false counts: "Keine Sperrstunde"
     * is a known fact.
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
