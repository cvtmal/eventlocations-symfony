<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * An automatic task needs an assignee (D24), and neither the record's owners nor any admin is active.
 */
final class NoTaskAssignee extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function forDeal(string $dealId): self
    {
        return new self(\sprintf('Nobody active can take the renewal task of deal "%s".', $dealId));
    }

    public static function forCompany(string $companyId): self
    {
        return new self(\sprintf('Nobody active can take the invoice task of company "%s".', $companyId));
    }

    public static function forProRequest(string $companyId): self
    {
        return new self(\sprintf('Nobody active can take the Pro request task of company "%s".', $companyId));
    }
}
