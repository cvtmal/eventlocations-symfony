<?php

declare(strict_types=1);

namespace App\Search\ValueObject;

/**
 * The visitor's point on the near-me page (#52), from `?lat=…&lng=…`. Both are rounded to 2 dp
 * (about 1.1 km), which is all the page needs and bounds the cache keys; anything that does not
 * parse or lies outside the box is refused, never an exception.
 */
final readonly class NearbyPoint
{
    /** The venue CHECK's CH box (45.8–47.9, 5.9–10.6) plus about 30 km on each side. */
    public const float MIN_LAT = 45.5;
    public const float MAX_LAT = 48.1;
    public const float MIN_LNG = 5.5;
    public const float MAX_LNG = 10.9;
    private const string PATTERN = '/^-?\d{1,3}(\.\d{1,12})?$/';

    private function __construct(
        public float $lat,
        public float $lng,
    ) {
    }

    public static function fromQuery(mixed $lat, mixed $lng): ?self
    {
        if (!\is_string($lat) || !\is_string($lng) || 1 !== preg_match(self::PATTERN, $lat) || 1 !== preg_match(self::PATTERN, $lng)) {
            return null;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;
        if ($lat < self::MIN_LAT || $lat > self::MAX_LAT || $lng < self::MIN_LNG || $lng > self::MAX_LNG) {
            return null;
        }

        return new self(round($lat, 2), round($lng, 2));
    }

    /**
     * A town's centre for a typed PLZ or Ort, unrounded: the key of that page is the typed value,
     * and the centre stays the one the listing's radius uses.
     */
    public static function town(float $lat, float $lng): self
    {
        return new self($lat, $lng);
    }

    /**
     * @return array{lat: string, lng: string}
     */
    public function toQuery(): array
    {
        return ['lat' => \sprintf('%.2f', $this->lat), 'lng' => \sprintf('%.2f', $this->lng)];
    }
}
