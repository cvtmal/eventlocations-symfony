<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * A task needs an assignee (D24), and neither the deal's owner, the company's owner nor any admin is active.
 */
final class NoRenewalAssignee extends DomainException
{
    private function __construct(string $dealId)
    {
        parent::__construct(\sprintf('Nobody active can take the renewal task of deal "%s".', $dealId));
    }

    public static function forDeal(string $dealId): self
    {
        return new self($dealId);
    }
}
