<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * No company has this id (deleted, or never existed). Pages answer 404.
 */
final class CompanyNotFound extends DomainException
{
    private function __construct(public readonly string $companyId)
    {
        parent::__construct(\sprintf('There is no company "%s".', $companyId));
    }

    public static function forId(string $companyId): self
    {
        return new self($companyId);
    }
}
