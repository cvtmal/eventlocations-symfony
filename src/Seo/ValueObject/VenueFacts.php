<?php

declare(strict_types=1);

namespace App\Seo\ValueObject;

use App\Geo\ValueObject\Canton;
use App\Venues\ReadModel\VenueDetail;

/**
 * The venue as Seo sees it. Every place fact is optional, because a venue need not have an
 * address (#21): the builders omit what is missing, never fill it. fromDetail() is the one
 * adapter from the detail read model.
 */
final readonly class VenueFacts
{
    /**
     * @param list<string> $imageUrls absolute
     * @param list<string> $sameAs    absolute
     */
    public function __construct(
        public string $name,
        public string $url,
        public string $description,
        public ?int $capacityMax,
        public ?string $street,
        public ?string $zip,
        public ?string $town,
        public ?Canton $canton,
        public ?float $lat,
        public ?float $lng,
        public ?float $ratingScore,
        public ?int $ratingCount,
        public array $imageUrls,
        public array $sameAs,
    ) {
    }

    /**
     * Town-centre coordinates (#24's "Ortsmitte übernehmen") are not the venue's point, so they
     * become no geo data: og:type stays `website` and the JSON-LD has no `geo`.
     *
     * @param list<string> $imageUrls absolute
     */
    public static function fromDetail(VenueDetail $venue, string $url, array $imageUrls): self
    {
        [$town, $canton] = [$venue->place->townName, $venue->place->canton];

        return new self(
            $venue->name,
            $url,
            $venue->metaDescription ?? $venue->summary,
            $venue->capacityMax,
            $venue->street,
            $venue->zip,
            $town,
            $canton,
            $venue->coordinatesFromTown ? null : $venue->lat,
            $venue->coordinatesFromTown ? null : $venue->lng,
            $venue->ratingScore,
            $venue->ratingCount,
            $imageUrls,
            array_values(array_filter([$venue->website, $venue->instagram], static fn (?string $url): bool => null !== $url)),
        );
    }

    public function hasPostalAddress(): bool
    {
        return null !== $this->zip && '' !== $this->zip && $this->hasTown();
    }

    public function hasTown(): bool
    {
        return null !== $this->town && '' !== $this->town;
    }

    public function hasCoordinates(): bool
    {
        return null !== $this->lat && null !== $this->lng;
    }
}
