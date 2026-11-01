<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * A company with a won deal takes its stage from its deals (S13, D9, L2), so the field cannot be set by hand. The API
 * answers 422 on `value`.
 */
final class CompanyStageDerived extends DomainException
{
    private function __construct(public readonly string $companyId)
    {
        parent::__construct(\sprintf('The stage of company "%s" comes from its deals.', $companyId));
    }

    public static function forCompany(string $companyId): self
    {
        return new self($companyId);
    }
}
