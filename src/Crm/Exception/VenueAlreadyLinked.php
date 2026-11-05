<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * The venue already belongs to a company (`uk_crm_companies_venue_id`: one Location per venue).
 * Nothing was written.
 */
final class VenueAlreadyLinked extends DomainException
{
    private function __construct(public readonly string $venueId)
    {
        parent::__construct(\sprintf('Venue "%s" already belongs to a company.', $venueId));
    }

    public static function forVenue(string $venueId): self
    {
        return new self($venueId);
    }
}
