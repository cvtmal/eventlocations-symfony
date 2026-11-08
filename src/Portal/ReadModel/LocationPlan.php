<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

/**
 * What the plan card on a manager's location page shows (F12): the tier's name, how much of each limit the location
 * uses, what the tier allows, and whether "Pro anfragen" is on offer. The used numbers count every row of the
 * location, hidden ones included, so a location that fell back to a smaller plan reads "8 von 6".
 *
 * `canRequestPro` is false on the Pro tier itself and when there is no Pro row to ask for; `proPriceRappen` is that
 * row's price (CHF a year as shown, final, invoiced by hand; D24) and null exactly when there is none. `proVatLine`
 * is that row's VAT flag: the card adds "zzgl. MwSt." only when it is on (off by default).
 */
final readonly class LocationPlan
{
    public function __construct(
        public string $tierName,
        public bool $promoted,
        public bool $canRequestPro,
        public int $photosUsed,
        public int $photosMax,
        public int $roomsUsed,
        public int $roomsMax,
        public int $documentsUsed,
        public int $documentsMax,
        public bool $contactVisible,
        public ?int $eventTypesMax,
        public ?int $proPriceRappen,
        public bool $proVatLine = false,
    ) {
    }
}
