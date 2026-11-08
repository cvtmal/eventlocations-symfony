<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\VenuePlace;

/**
 * One venue card of the listing (LP-2): everything the card renders, including its first
 * eight venue-level photos in position order (fewer when the venue's tier allows fewer, F12),
 * and the owner's price level and ambience as tags (LP-7, both NULL when not stated).
 * `promoted` is the "Top-Eintrag" label of a venue whose tier ranks above the unboosted ones.
 *
 * The capacity range spans the venue's and its rooms' ranges (F5b, see ListingQuery); the
 * event type is the venue's first `suitable_for` code in vocabulary order, NULL without one,
 * and picks the photo placeholder's icon (F5b).
 */
final readonly class ListingCard
{
    /**
     * @param list<CardPhoto> $photos     at most 8 and at most the tier's photo limit, in position order
     * @param bool            $lastMinute G14: a live offer «Kurzfristig frei» the plan shows (`LastMinuteOfferPolicy`)
     */
    public function __construct(
        public string $id,
        public int $number,
        public string $slug,
        public string $name,
        public bool $promoted,
        public ?int $capacityMin,
        public int $capacityMax,
        public VenuePlace $place,
        public string $summary,
        public ?PriceLevel $priceLevel,
        public ?string $ambience,
        public array $photos,
        public ?string $eventType = null,
        public bool $lastMinute = false,
    ) {
    }
}
