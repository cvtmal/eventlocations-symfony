<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * One result of a Places Text Search (#57, `VenueGeocoder::candidates()`): what the place ID lookup needs to
 * decide whether it is our venue. `cantonCode` is the `administrative_area_level_1` short text lower-cased,
 * `countryCode` the `country` short text upper-cased, both '' when Google names none. `areaNames` are the long
 * texts of the locality-level components (town, district, canton, country), never a street, house number or
 * postal code. `townNames` are the town-level ones among them only (`locality`, `postal_town`, `sublocality*`): a
 * canton or district name says nothing about which town (#57, review R2-M1). This is Google Maps Content: used
 * for the report only, never stored.
 */
final readonly class GooglePlaceCandidate
{
    /**
     * @param list<string> $types
     * @param list<string> $areaNames
     * @param list<string> $townNames
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $address,
        public float $lat,
        public float $lng,
        public array $types,
        public string $cantonCode,
        public string $countryCode,
        public string $businessStatus,
        public array $areaNames,
        public array $townNames,
    ) {
    }
}
