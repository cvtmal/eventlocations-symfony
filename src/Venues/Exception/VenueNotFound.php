<?php

declare(strict_types=1);

namespace App\Venues\Exception;

use App\Shared\Exception\DomainException;

/**
 * No venue has this public number (deleted, or never existed). The editor answers 404.
 */
final class VenueNotFound extends DomainException
{
    private function __construct(public readonly int $venueNumber)
    {
        parent::__construct(\sprintf('There is no venue %d.', $venueNumber));
    }

    public static function forNumber(int $venueNumber): self
    {
        return new self($venueNumber);
    }
}
