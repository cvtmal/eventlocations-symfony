<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Erreichbarkeit (FI-4): near a station, a motorway or an airport. Derived from the
 * venue's distances (`venues.distance_<station|highway|airport>_km`), never stored.
 *
 * The thresholds of maxDistanceKm() are eventlokale's (≤ 1 / ≤ 10 / ≤ 30 km, measured
 * 2026-10-30, cycle #6). A NULL distance is "not near". The listing filter (#6), the detail
 * page (#8, DP-10) and the filter overlay (#12) all read the same method.
 */
enum Access: string implements TranslatableInterface
{
    case Station = 'station';
    case Highway = 'highway';
    case Airport = 'airport';

    /**
     * A venue is near this kind of transport when its distance is at most this many km.
     */
    public function maxDistanceKm(): float
    {
        return match ($this) {
            self::Station => 1.0,
            self::Highway => 10.0,
            self::Airport => 30.0,
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('access.'.$this->value, [], 'venues', $locale);
    }
}
