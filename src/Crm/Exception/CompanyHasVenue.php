<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * A Location has one Eintrag at most (#56): assigning a second one is refused; release the first.
 * Nothing was written.
 */
final class CompanyHasVenue extends DomainException
{
    private function __construct(public readonly string $companyId)
    {
        parent::__construct(\sprintf('Company "%s" already has a venue.', $companyId));
    }

    public static function forCompany(string $companyId): self
    {
        return new self($companyId);
    }
}
