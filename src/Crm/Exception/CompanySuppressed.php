<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * The Location is on the suppression list (G11), so it stays: deleting it would end the opt-out.
 */
final class CompanySuppressed extends DomainException
{
    private function __construct(public readonly string $companyId)
    {
        parent::__construct(\sprintf('The company "%s" is on the suppression list.', $companyId));
    }

    public static function forCompany(string $companyId): self
    {
        return new self($companyId);
    }
}
