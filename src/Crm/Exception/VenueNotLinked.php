<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * A deal's venue is one of its company's venues (CRM-7): `crm_company_venues` holds the link, and `venue_id` alone
 * would not prove it.
 */
final class VenueNotLinked extends DomainException
{
    private function __construct(public readonly string $venueId, public readonly string $companyId)
    {
        parent::__construct(\sprintf('The venue "%s" is not linked to company "%s".', $venueId, $companyId));
    }

    public static function toCompany(string $venueId, string $companyId): self
    {
        return new self($venueId, $companyId);
    }
}
