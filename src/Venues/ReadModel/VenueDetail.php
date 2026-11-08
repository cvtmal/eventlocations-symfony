<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Media\ValueObject\Picture;
use App\Venues\ValueObject\OwnCatering;
use App\Venues\ValueObject\Term;
use App\Venues\ValueObject\VenuePlace;

/**
 * Everything the venue detail page (`venue_show`, DP-2 to DP-17) renders, in one locale. The
 * phone number itself is not part of it (DP-11: never in the page HTML), only `hasPhone`.
 * What the venue's tier does not allow is already cut here (F12): `hasPhone` and `website` are
 * empty on a tier without visible contact data, `documents`, `rooms` and `photos` hold the
 * first N by position, and `promoted` is the "Top-Eintrag" label. Templates decide nothing.
 * `coordinatesFromTown` says lat/lng are the town centre ("Ortsmitte übernehmen", #24), not
 * the venue's own point: no map pin and no geo data may claim them. `googlePlaceId` is the only
 * Google value we hold (#53); the rating itself is never read or stored, Google's element shows it.
 */
final readonly class VenueDetail
{
    /**
     * @param list<DetailFactGroup> $factGroups in DP-7 order, empty groups dropped
     * @param list<DetailDistance>  $distances  station, highway, airport (those with a distance)
     * @param list<DetailDocument>  $documents  in position order
     * @param list<DetailRoom>      $rooms      in position order
     * @param list<Picture>         $photos     venue-level photos in position order
     * @param ?string               $instagram  `https://www.instagram.com/<handle>`, null when the stored value names no profile (`InstagramProfile`)
     */
    public function __construct(
        public string $id,
        public int $number,
        public string $name,
        public bool $promoted,
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
        public ?OwnCatering $ownCatering,
        public ?bool $daylight,
        public ?string $parkingText,
        public array $distances,
        public bool $hasPhone,
        public ?string $inquiryEmail,
        public ?string $website,
        public ?string $contactPerson,
        public array $documents,
        public array $rooms,
        public array $photos,
        public DetailEvent $event,
        public ?string $instagram,
        public bool $coordinatesFromTown,
        public ?string $googlePlaceId,
        public bool $googleRatingHidden,
        /** G12: whether the entry passes `VenueIndexGate` (facts checked, description written); else the page is noindex. */
        public bool $indexable,
    ) {
    }

    /**
     * Whether the page shows Google's rating (#53): a place ID is set and staff have not hidden it.
     */
    public function showsGoogleRating(): bool
    {
        return null !== $this->googlePlaceId && !$this->googleRatingHidden;
    }

    /**
     * Whether the page may show Google Places photos (D12): a place ID is set and the venue has no photo the public
     * may see (`$photos` is already cut by PublicPhotoPolicy, the G2 hold and the F12 PhotoBudget). Its own granted
     * photos replace Google's the moment they are public. Staff's rating switch does not hide the photos.
     */
    public function showsGooglePhotos(): bool
    {
        return null !== $this->googlePlaceId && [] === $this->photos;
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
