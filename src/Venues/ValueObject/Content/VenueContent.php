<?php

declare(strict_types=1);

namespace App\Venues\ValueObject\Content;

use App\Venues\ValueObject\OwnCatering;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenueStatus;

/**
 * One validated content folder (venue.yaml and its files), as ContentReader read it.
 * Decimals are strings in the entities' format ('0.9', '6.50').
 */
final readonly class VenueContent
{
    /**
     * @param array<string, list<string>>                                                                                                                                                                   $terms        codes by TermGroup value
     * @param array<string, string>                                                                                                                                                                         $mainTerms    code by TermGroup value
     * @param array<string, array{summary: string, description: ?string, metaDescription: ?string, parkingText: ?string, ambience: ?string, capacityNote: ?string, openingHours: ?string, region: ?string}> $translations by locale
     * @param list<PhotoContent>                                                                                                                                                                            $photos       gallery photos
     * @param list<RoomContent>                                                                                                                                                                             $rooms
     * @param list<DocumentContent>                                                                                                                                                                         $documents
     */
    public function __construct(
        public string $key,
        public string $contentHash,
        public string $name,
        public Tier $tier,
        public VenueStatus $status,
        public int $capacityMax,
        public ?int $capacityMin,
        public ?string $street,
        public ?string $zip,
        public ?string $townName,
        public ?string $cantonCode,
        public ?float $lat,
        public ?float $lng,
        public ?string $stationKm,
        public ?string $stationName,
        public ?string $highwayKm,
        public ?string $highwayName,
        public ?string $airportKm,
        public ?string $airportName,
        public ?string $phone,
        public ?string $inquiryEmail,
        public ?string $website,
        public ?string $contactPerson,
        public ?OwnCatering $ownCatering,
        public ?bool $daylight,
        public ?PriceLevel $priceLevel,
        public ?int $capacitySeated,
        public ?int $capacityStanding,
        public ?string $instagram,
        public array $terms,
        public array $mainTerms,
        public array $translations,
        public array $photos,
        public array $rooms,
        public array $documents,
    ) {
    }
}
