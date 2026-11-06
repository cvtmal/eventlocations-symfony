<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\ValueObject\GeocodeHit;
use App\Venues\ValueObject\GooglePlaceCandidate;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Finds a venue's position by name with Google's Places Text Search (new API), limited to a box
 * around Switzerland that also covers Liechtenstein (#46); `regionCode` CH only biases the ranking,
 * so a Liechtenstein venue is placed too. The key is a constructor argument and reaches no log,
 * file or message. The field mask also asks for the place ID (#53's lookup), which keeps the call a
 * Text Search Pro one. #57's `candidates()` asks up to five results with types, address components and
 * business status, still a Text Search Pro call.
 */
final class VenueGeocoder
{
    public const string ENDPOINT = 'https://places.googleapis.com/v1/places:searchText';

    public const float LAT_MIN = 45.8;
    public const float LAT_MAX = 47.9;
    public const float LNG_MIN = 5.9;
    public const float LNG_MAX = 10.6;

    /** #57's field mask: every field is Text Search Pro, so asking for them costs no more than #53's call. */
    public const string CANDIDATE_FIELDS = 'places.id,places.displayName,places.formattedAddress,places.location,places.types,places.addressComponents,places.businessStatus';

    private const int MAX_CANDIDATES = 5;

    /** Address component types that name an area; `sublocality*` is matched by prefix. */
    private const array AREA_TYPES = ['locality', 'postal_town', 'administrative_area_level_1', 'administrative_area_level_2', 'country'];

    /** The town-level ones among them; `sublocality*` is matched by prefix. */
    private const array TOWN_TYPES = ['locality', 'postal_town'];

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

    /**
     * Up to five places in Google's rank order (#57), each inside the box and with an ID; an entry without an ID
     * or a location is dropped.
     *
     * @return list<GooglePlaceCandidate>
     *
     * @throws ExceptionInterface on a transport error or a status other than 200
     */
    public function candidates(string $query): array
    {
        $data = $this->client->request('POST', self::ENDPOINT, [
            'headers' => [
                'X-Goog-Api-Key' => $this->apiKey,
                'X-Goog-FieldMask' => self::CANDIDATE_FIELDS,
            ],
            'json' => [
                'textQuery' => $query,
                'languageCode' => 'de',
                'regionCode' => 'CH',
                'pageSize' => self::MAX_CANDIDATES,
                'locationRestriction' => ['rectangle' => [
                    'low' => ['latitude' => self::LAT_MIN, 'longitude' => self::LNG_MIN],
                    'high' => ['latitude' => self::LAT_MAX, 'longitude' => self::LNG_MAX],
                ]],
            ],
        ])->toArray();

        $places = \is_array($data['places'] ?? null) ? $data['places'] : [];
        $candidates = [];
        foreach (\array_slice(array_values($places), 0, self::MAX_CANDIDATES) as $place) {
            $candidate = \is_array($place) ? self::candidate($place) : null;
            if (null !== $candidate) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * @param array<mixed> $place
     */
    private static function candidate(array $place): ?GooglePlaceCandidate
    {
        $id = $place['id'] ?? null;
        $location = $place['location'] ?? null;
        if (!\is_string($id) || '' === $id || !\is_array($location) || !is_numeric($location['latitude'] ?? null) || !is_numeric($location['longitude'] ?? null)) {
            return null;
        }
        $lat = (float) $location['latitude'];
        $lng = (float) $location['longitude'];
        if ($lat < self::LAT_MIN || $lat > self::LAT_MAX || $lng < self::LNG_MIN || $lng > self::LNG_MAX) {
            return null;
        }

        $types = [];
        foreach (\is_array($place['types'] ?? null) ? $place['types'] : [] as $type) {
            if (\is_string($type)) {
                $types[] = $type;
            }
        }

        $cantonCode = '';
        $countryCode = '';
        $areaNames = [];
        $townNames = [];
        foreach (\is_array($place['addressComponents'] ?? null) ? $place['addressComponents'] : [] as $component) {
            if (!\is_array($component) || !\is_array($component['types'] ?? null)) {
                continue;
            }
            $componentTypes = array_values(array_filter($component['types'], 'is_string'));
            $short = \is_string($component['shortText'] ?? null) ? $component['shortText'] : '';
            $long = \is_string($component['longText'] ?? null) ? $component['longText'] : '';
            if ('' === $cantonCode && \in_array('administrative_area_level_1', $componentTypes, true)) {
                $cantonCode = strtolower($short);
            }
            if ('' === $countryCode && \in_array('country', $componentTypes, true)) {
                $countryCode = strtoupper($short);
            }
            if ('' !== $long && self::isOneOf($componentTypes, self::AREA_TYPES) && !\in_array($long, $areaNames, true)) {
                $areaNames[] = $long;
            }
            if ('' !== $long && self::isOneOf($componentTypes, self::TOWN_TYPES) && !\in_array($long, $townNames, true)) {
                $townNames[] = $long;
            }
        }

        return new GooglePlaceCandidate(
            $id,
            \is_string($place['displayName']['text'] ?? null) ? $place['displayName']['text'] : '',
            \is_string($place['formattedAddress'] ?? null) ? $place['formattedAddress'] : '',
            round($lat, 6),
            round($lng, 6),
            $types,
            $cantonCode,
            $countryCode,
            \is_string($place['businessStatus'] ?? null) ? $place['businessStatus'] : '',
            $areaNames,
            $townNames,
        );
    }

    /**
     * A component of one of these types or a `sublocality*` one: with `AREA_TYPES` a town, district, canton or
     * country, with `TOWN_TYPES` a town only; a street, house number, building or postal code never is.
     *
     * @param list<string> $types
     * @param list<string> $wanted
     */
    private static function isOneOf(array $types, array $wanted): bool
    {
        foreach ($types as $type) {
            if (\in_array($type, $wanted, true) || str_starts_with($type, 'sublocality')) {
                return true;
            }
        }

        return false;
    }
}
