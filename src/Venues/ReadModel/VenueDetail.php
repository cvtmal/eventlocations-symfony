<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Media\ValueObject\Picture;
use App\Venues\ValueObject\OwnCatering;
use App\Venues\ValueObject\RatingHighlight;
use App\Venues\ValueObject\RatingLabel;
use App\Venues\ValueObject\Term;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenuePlace;

/**
 * Everything the venue detail page (`venue_show`, DP-2 to DP-17) renders, in one locale. The
 * phone number itself is not part of it (DP-11: never in the page HTML), only `hasPhone`.
 */
final readonly class VenueDetail
{
    /**
     * @param list<DetailFactGroup> $factGroups in DP-7 order, empty groups dropped
     * @param list<RatingHighlight> $highlights at most three, in position order
     * @param list<DetailDistance>  $distances  station, highway, airport (those with a distance)
     * @param list<DetailDocument>  $documents  in position order
     * @param list<DetailReview>    $reviews    newest first
     * @param list<DetailRoom>      $rooms      in position order
     * @param list<Picture>         $photos     venue-level photos in position order
     */
    public function __construct(
        public string $id,
        public int $number,
        public string $name,
        public Tier $tier,
        public string $slug,
        public string $summary,
        public ?string $description,
        public ?string $metaDescription,
        public ?string $street,
        public ?string $zip,
        public VenuePlace $place,
        public ?float $lat,
        public ?float $lng,
        public ?int $capacityMin,
        public int $capacityMax,
        public ?Term $mainBuilding,
        public ?Term $mainSetting,
        public array $factGroups,
        public ?float $ratingScore,
        public ?int $ratingCount,
        public ?RatingLabel $ratingLabel,
        public array $highlights,
        public ?OwnCatering $ownCatering,
        public ?bool $daylight,
        public ?string $parkingText,
        public array $distances,
        public bool $hasPhone,
        public ?string $inquiryEmail,
        public ?string $website,
        public ?string $contactPerson,
        public array $documents,
        public array $reviews,
        public array $rooms,
        public array $photos,
        public DetailWedding $wedding,
        public ?string $instagram,
    ) {
    }

    /**
     * The head block's text (D13): the description, or the summary when there is none.
     */
    public function descriptionText(): string
    {
        return $this->description ?? $this->summary;
    }

    public function hasFacts(): bool
    {
        return [] !== $this->factGroups;
    }

    public function hasRatingSection(): bool
    {
        return null !== $this->ratingScore && null !== $this->ratingCount;
    }

    public function hasFaq(): bool
    {
        return null !== $this->ownCatering || null !== $this->daylight || null !== $this->parkingText;
    }

    /**
     * Whether the Standort section has anything to show (town, coordinates or distances).
     */
    public function hasLocation(): bool
    {
        return null !== $this->place->townName || null !== $this->lat || [] !== $this->distances;
    }

    public function hasContact(): bool
    {
        return $this->hasPhone || null !== $this->inquiryEmail || null !== $this->website || null !== $this->instagram || null !== $this->contactPerson;
    }

    /**
     * Share-image paths of the first photos, as plain strings: Seo may not name a Media type.
     *
     * @return list<string>
     */
    public function imageUrls(int $limit): array
    {
        return array_map(
            static fn (Picture $photo): string => $photo->shareImage()->url,
            \array_slice($this->photos, 0, $limit),
        );
    }
}
