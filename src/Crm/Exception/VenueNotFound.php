<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * No venue has this id, so it cannot be linked to a company. Crm's own error, so the module never
 * throws one of Venues' exceptions. Pages answer 404.
 */
final class VenueNotFound extends DomainException
{
    private function __construct(public readonly string $venueId)
    {
        parent::__construct(\sprintf('There is no venue "%s".', $venueId));
    }

    public static function forId(string $venueId): self
    {
        return new self($venueId);
    }
}
