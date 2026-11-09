<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Venues\ReadModel\LastMinuteOfferView;

/**
 * One venue on the page «Kurzfristig frei» (G14): its card and its live offers, soonest first. `inquirable`: it has an
 * inquiry address, so its detail page has the form at `#anfrage` (F16).
 */
final readonly class LastMinuteEntry
{
    /**
     * @param list<LastMinuteOfferView> $offers
     */
    public function __construct(
        public ListingCard $card,
        public array $offers,
        public bool $inquirable,
    ) {
    }
}
