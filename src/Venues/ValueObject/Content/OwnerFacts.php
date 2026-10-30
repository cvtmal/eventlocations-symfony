<?php

declare(strict_types=1);

namespace App\Venues\ValueObject\Content;

use App\Venues\ValueObject\OwnCatering;
use App\Venues\ValueObject\PartyAllowed;
use App\Venues\ValueObject\PriceLevel;

/**
 * One CSV row as the S14 facts #32 owns in a venue.yaml (research D1). A null or an empty
 * list means the owner stated nothing that maps, and the writer removes that path.
 */
final readonly class OwnerFacts
{
    /**
     * @param ?string                     $curfewAt  `HH:MM`, only with $hasCurfew true
     * @param array<string, list<string>> $terms     group to its sorted codes, groups in TermGroup order, no empty group
     * @param array<string, string>       $mainTerms `setting`, `building`, `suitable_for` to one code listed in $terms
     * @param list<string>                $notes     what the owner should look at; never a cell's value
     * @param array<string, list<string>> $unmapped  folded section label (or `typ`, `preisniveau`) to the
     *                                               tokens no table holds, for the report file only: they are cells
     * @param bool                        $spotCheck civil_ceremony came from the older form's
     *                                               `Räumlichkeiten für standesamtliche Hochzeiten` (D7)
     */
    public function __construct(
        public ?PriceLevel $priceLevel,
        public ?int $seated,
        public ?int $standing,
        public ?PartyAllowed $partyAllowed,
        public ?bool $hasCurfew,
        public ?string $curfewAt,
        public ?OwnCatering $ownCatering,
        public array $terms,
        public array $mainTerms,
        public ?string $ambience,
        public ?string $capacityNote,
        public ?string $partyNote,
        public ?string $openingHours,
        public ?string $parkingText,
        public array $notes,
        public array $unmapped,
        public bool $spotCheck,
    ) {
    }
}
