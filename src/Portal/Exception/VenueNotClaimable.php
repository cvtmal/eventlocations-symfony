<?php

declare(strict_types=1);

namespace App\Portal\Exception;

use App\Shared\Exception\DomainException;

/**
 * A manager can only ask for a location that exists and is published. It may have left the search between
 * the page and the click, so the screen says so.
 */
final class VenueNotClaimable extends DomainException
{
    private function __construct(public readonly int $number)
    {
        parent::__construct(\sprintf('Venue %d cannot be claimed.', $number));
    }

    public static function forNumber(int $number): self
    {
        return new self($number);
    }
}
