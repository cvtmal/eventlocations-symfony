<?php

declare(strict_types=1);

namespace App\Seo;

use App\Seo\ValueObject\VenueFacts;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * SEO-2: the venue node. Its type follows the data: Google requires `address` for LocalBusiness,
 * so a venue without ZIP and town is a plain EventVenue, and review stars need LocalBusiness
 * too. Missing facts are left out, never filled. `telephone` stays out on purpose: JSON-LD is
 * page HTML and DP-11 keeps the number out of it. `email` stays out per SEO-2.
 */
final class VenueJsonLd
{
    private const int MAX_IMAGES = 3;
    private const int BEST_RATING = 10;

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(VenueFacts $facts): array
    {
        $business = $facts->hasPostalAddress();

        return self::withoutEmpty([
            '@context' => 'https://schema.org',
            '@type' => $business ? ['EventVenue', 'LocalBusiness'] : 'EventVenue',
            '@id' => $facts->url.'#venue',
            'name' => $facts->name,
            'url' => $facts->url,
            'description' => $facts->description,
            'address' => $business ? $this->address($facts) : null,
            'containedInPlace' => $business ? null : $this->canton($facts),
            'geo' => self::geo($facts),
            'maximumAttendeeCapacity' => $facts->capacityMax,
            'image' => \array_slice($facts->imageUrls, 0, self::MAX_IMAGES),
            'sameAs' => $facts->sameAs,
            'aggregateRating' => $business ? self::rating($facts) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function address(VenueFacts $facts): array
    {
        return self::withoutEmpty([
            '@type' => 'PostalAddress',
            'streetAddress' => $facts->street,
            'postalCode' => $facts->zip,
            'addressLocality' => $facts->town,
            'addressRegion' => $facts->canton?->trans($this->translator),
            'addressCountry' => $facts->canton?->countryCode() ?? 'CH',
        ]);
    }

    /**
     * @return array<string, string>|null
     */
    private function canton(VenueFacts $facts): ?array
    {
        if (null === $facts->canton) {
            return null;
        }

        return [
            '@type' => 'AdministrativeArea',
            'name' => $this->translator->trans('venue.canton_area', ['canton' => $facts->canton->trans($this->translator), 'canton_code' => $facts->canton->value], 'seo'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function geo(VenueFacts $facts): ?array
    {
        if (!$facts->hasCoordinates()) {
            return null;
        }

        return ['@type' => 'GeoCoordinates', 'latitude' => $facts->lat, 'longitude' => $facts->lng];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function rating(VenueFacts $facts): ?array
    {
        if (null === $facts->ratingScore || null === $facts->ratingCount || $facts->ratingCount <= 0) {
            return null;
        }

        return [
            '@type' => 'AggregateRating',
            'ratingValue' => $facts->ratingScore,
            'ratingCount' => $facts->ratingCount,
            'bestRating' => self::BEST_RATING,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function withoutEmpty(array $data): array
    {
        return array_filter($data, static fn (mixed $value): bool => !\in_array($value, [null, '', []], true));
    }
}
