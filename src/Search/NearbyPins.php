<?php

declare(strict_types=1);

namespace App\Search;

use App\Search\ReadModel\NearbyVenue;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The near-me map's pins as JSON for one `data-near-me-pins` attribute (#52), which Twig escapes:
 * `[{"a": lat, "o": lng, "v": [{"n": name, "u": url, "p": place, "k": "4 km"}]}]`. Venues on one
 * point (rounded to 4 dp, about 11 m: several share a town centre) are one pin, in the order
 * given; `k` only with a distance. Short keys keep the 400-venue page small.
 */
final class NearbyPins
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Whole km, at least 1. The value stays below 1,000, so it needs no group separator.
     */
    public static function km(float $distanceKm): int
    {
        return max(1, (int) round($distanceKm));
    }

    /**
     * @param list<NearbyVenue> $venues
     */
    public function json(array $venues, string $locale): string
    {
        $pins = [];
        foreach ($venues as $venue) {
            $lat = round($venue->lat, 4);
            $lng = round($venue->lng, 4);
            $key = \sprintf('%.4f,%.4f', $lat, $lng);

            $entry = [
                'n' => $venue->name,
                'u' => $this->urls->generate('venue_show', ['slug' => $venue->slug]),
                'p' => $venue->place->isKnown() ? $venue->place->trans($this->translator, $locale) : '',
            ];
            if (null !== $venue->distanceKm) {
                $entry['k'] = $this->translator->trans('nearby.distance', ['km' => (string) self::km($venue->distanceKm)], 'search', $locale);
            }

            $pins[$key] ??= ['a' => $lat, 'o' => $lng, 'v' => []];
            $pins[$key]['v'][] = $entry;
        }

        return json_encode(array_values($pins), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
