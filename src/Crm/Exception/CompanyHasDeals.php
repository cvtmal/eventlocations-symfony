<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * A company with a deal cannot be deleted (D7): its contract and money history would go with it. Delete the deals
 * first; the record page shows a flash.
 */
final class CompanyHasDeals extends DomainException
{
    private function __construct(public readonly string $companyId)
    {
        parent::__construct(\sprintf('The company "%s" still has deals.', $companyId));
    }

    public static function forCompany(string $companyId): self
    {
        return new self($companyId);
    }
}
