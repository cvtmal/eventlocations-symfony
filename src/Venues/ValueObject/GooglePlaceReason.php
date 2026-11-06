<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * Why the place ID lookup (#57) trusts a row or not, in the order `GooglePlaceMatcher` checks: the first failing
 * check is the row's reason. The value is the report's code and the key `google_place.reason.<value>` of the
 * `venues` catalogue (`GooglePlaceVerdict` renders it). `SamePlace` is the finder's cross-row check.
 */
enum GooglePlaceReason: string
{
    case Failed = 'failed';
    case NoResult = 'no_result';
    case NotAVenue = 'not_a_venue';
    case Closed = 'closed';
    case GenericName = 'generic_name';
    case NoPlace = 'no_place';
    case NameDiffers = 'name_differs';
    case GoogleNameLonger = 'google_name_longer';
    case PlaceNameOnly = 'place_name_only';
    case CommonName = 'common_name';
    case CantonUnknown = 'canton_unknown';
    case PlaceDiffers = 'place_differs';
    case FarFromPin = 'far_from_pin';
    case Ambiguous = 'ambiguous';
    case SamePlace = 'same_place';
    case Match = 'match';

    /** The report's `confidence` column: only a match is trusted. */
    public function confidence(): string
    {
        return match ($this) {
            self::Match => 'confident',
            self::Failed => 'failed',
            self::NoResult => 'not found',
            default => 'doubtful',
        };
    }
}
