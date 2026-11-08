<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * One Google Places photo of a venue's place (D12), as one Place Details answer named it. Held for one request and
 * never stored: Google's photo name expires and "cannot be cached" (Place Photos docs), so it lives only in the
 * no-store fragment and in the image URL our page asks for.
 *
 * `reference` is the last segment of Google's name `places/{place id}/photos/{reference}`; the controller rebuilds
 * the name from the venue's own place ID (`GooglePlacesPhotos::image()`), so a URL can only ever name a photo of
 * that venue's place. `author` is the
 * uploader's name, which the page shows on the photo (D12, Places policies); `authorUri` (their Google Maps
 * profile) and `mapsUri` (the photo on Google Maps, the "source photo" the policies require access to) are https
 * links or null.
 */
final readonly class GooglePlacePhoto
{
    /** The characters of a reference, as a route requirement (no slash, no dot, so no path tricks). */
    public const string REFERENCE_REQUIREMENT = '[A-Za-z0-9_-]{1,1024}';

    public function __construct(
        public string $reference,
        public int $width,
        public int $height,
        public string $author,
        public ?string $authorUri,
        public ?string $mapsUri,
    ) {
    }

    /**
     * Reads one entry of a Place Details `photos` list. Null when it is not a photo of `$placeId` or names no
     * uploader: a photo whose author the page cannot show is not shown at all.
     */
    public static function fromApi(string $placeId, mixed $photo): ?self
    {
        if (!\is_array($photo) || !\is_string($photo['name'] ?? null)) {
            return null;
        }
        $prefix = 'places/'.$placeId.'/photos/';
        $reference = str_starts_with($photo['name'], $prefix) ? substr($photo['name'], \strlen($prefix)) : '';
        if (1 !== preg_match('/\A'.self::REFERENCE_REQUIREMENT.'\z/', $reference)) {
            return null;
        }

        $attribution = null;
        foreach (\is_array($photo['authorAttributions'] ?? null) ? $photo['authorAttributions'] : [] as $candidate) {
            if (\is_array($candidate) && \is_string($candidate['displayName'] ?? null) && '' !== trim($candidate['displayName'])) {
                $attribution = $candidate;
                break;
            }
        }
        if (null === $attribution) {
            return null;
        }

        $width = $photo['widthPx'] ?? null;
        $height = $photo['heightPx'] ?? null;

        return new self(
            $reference,
            \is_int($width) && $width > 0 ? $width : 4,
            \is_int($height) && $height > 0 ? $height : 3,
            trim((string) $attribution['displayName']),
            self::https($attribution['uri'] ?? null),
            self::https($photo['googleMapsUri'] ?? null),
        );
    }

    /**
     * An absolute https URL, or null: a value of another scheme (`javascript:`) must never reach an href.
     */
    private static function https(mixed $url): ?string
    {
        if (!\is_string($url) || 1 !== preg_match('~\Ahttps://[^\s/?#]+[^\s]*\z~', $url)) {
            return null;
        }

        return $url;
    }
}
