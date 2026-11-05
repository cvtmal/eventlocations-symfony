<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * A Google place ID (#53), the only Google value we may store (Places API policies: "the place_id is exempt from
 * caching restrictions"). PATTERN is chk_venues_google_place_id's character set and length; the CMS form, both
 * Google place handlers and the lookup report read it. Forms restrict the input first, so anything else that
 * reaches fromInput() is a programming error, as with the other input readers.
 */
final readonly class GooglePlaceId
{
    public const string PATTERN = '/\A[A-Za-z0-9_-]{10,255}\z/';

    private function __construct(
        public string $value,
    ) {
    }

    /**
     * Trims; an empty input is no place ID.
     *
     * @throws \InvalidArgumentException when the input is not a place ID
     */
    public static function fromInput(?string $input): ?self
    {
        $input = trim((string) $input);
        if ('' === $input) {
            return null;
        }
        if (1 !== preg_match(self::PATTERN, $input)) {
            throw new \InvalidArgumentException('The input is not a Google place ID.');
        }

        return new self($input);
    }

    /**
     * The Maps URL that opens this place (https://developers.google.com/maps/documentation/urls/get-started): the
     * public fallback link and the CMS check link.
     */
    public static function mapsUrl(string $venueName, string $placeId): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($venueName).'&query_place_id='.rawurlencode($placeId);
    }
}
