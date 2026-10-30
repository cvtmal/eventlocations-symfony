<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\RatingHighlight;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenuePlace;

/**
 * One venue card of the listing (LP-2): everything the card renders, including its first
 * eight venue-level photos in position order, and the owner's price level and ambience as
 * tags (LP-7, both NULL when not stated).
 */
final readonly class ListingCard
{
    /**
     * @param list<RatingHighlight> $highlights in position order
     * @param list<CardPhoto>       $photos     at most 8, in position order
     */
    public function __construct(
        public string $id,
        public int $number,
        public string $slug,
        public string $name,
        public Tier $tier,
        public ?int $capacityMin,
        public int $capacityMax,
        public VenuePlace $place,
        public string $summary,
        public ?PriceLevel $priceLevel,
        public ?string $ambience,
        public ?float $ratingScore,
        public ?int $ratingCount,
        public array $highlights,
        public array $photos,
    ) {
    }
}
