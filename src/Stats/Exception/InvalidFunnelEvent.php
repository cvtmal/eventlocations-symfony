<?php

declare(strict_types=1);

namespace App\Stats\Exception;

use App\Shared\Exception\DomainException;

/**
 * A `RecordFunnelEvent` the funnel log would not take. The messages never repeat the offending value,
 * because a wrong campaign code is exactly where a careless publisher might put an address.
 */
final class InvalidFunnelEvent extends DomainException
{
    public static function venueId(): self
    {
        return new self('A funnel event carries a venue UUID or none.');
    }

    public static function campaignCode(): self
    {
        return new self('A funnel event carries a plain campaign code (letters, digits, "_" and "-", at most 40) or none.');
    }
}
