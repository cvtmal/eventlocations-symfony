<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\ValueObject\GeocodeHit;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Finds a venue's position by name with Google's Places Text Search (new API), limited to a box
 * around Switzerland that also covers Liechtenstein (#46); `regionCode` CH only biases the ranking,
 * so a Liechtenstein venue is placed too. The key is a constructor argument and reaches no log,
 * file or message. The field mask also asks for the place ID (#53's lookup), which keeps the call a
 * Text Search Pro one.
 */
final class VenueGeocoder
{
    public const string ENDPOINT = 'https://places.googleapis.com/v1/places:searchText';

    public const float LAT_MIN = 45.8;
    public const float LAT_MAX = 47.9;
    public const float LNG_MIN = 5.9;
    public const float LNG_MAX = 10.6;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $apiKey,
    ) {
    }

    /**
     * The best match, or null when nothing was found or it lies outside the box.
     *
     * @throws ExceptionInterface on a transport error or a status other than 200
     */
    public function locate(string $query): ?GeocodeHit
    {
        $data = $this->client->request('POST', self::ENDPOINT, [
            'headers' => [
                'X-Goog-Api-Key' => $this->apiKey,
                'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.location',
            ],
            'json' => [
                'textQuery' => $query,
                'languageCode' => 'de',
                'regionCode' => 'CH',
                'maxResultCount' => 1,
                'locationRestriction' => ['rectangle' => [
                    'low' => ['latitude' => self::LAT_MIN, 'longitude' => self::LNG_MIN],
                    'high' => ['latitude' => self::LAT_MAX, 'longitude' => self::LNG_MAX],
                ]],
            ],
        ])->toArray();

        $place = $data['places'][0] ?? null;
        if (!\is_array($place) || !isset($place['location']['latitude'], $place['location']['longitude'])) {
            return null;
        }
        $lat = (float) $place['location']['latitude'];
        $lng = (float) $place['location']['longitude'];
        if ($lat < self::LAT_MIN || $lat > self::LAT_MAX || $lng < self::LNG_MIN || $lng > self::LNG_MAX) {
            return null;
        }

        return new GeocodeHit(
            \is_string($place['displayName']['text'] ?? null) ? $place['displayName']['text'] : '',
            \is_string($place['formattedAddress'] ?? null) ? $place['formattedAddress'] : '',
            round($lat, 6),
            round($lng, 6),
            \is_string($place['id'] ?? null) ? $place['id'] : '',
        );
    }
}
