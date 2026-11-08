<?php

declare(strict_types=1);

namespace App\Portal\Exception;

use App\Shared\Exception\DomainException;

/**
 * G6: too many codes for one address in an hour, or for one venue in a day. The message names neither.
 */
final class ClaimThrottled extends DomainException
{
    public static function forAddress(): self
    {
        return new self('Too many claim codes for this address in the last hour.');
    }

    public static function forVenue(): self
    {
        return new self('Too many claim codes for this venue in the last day.');
    }
}
