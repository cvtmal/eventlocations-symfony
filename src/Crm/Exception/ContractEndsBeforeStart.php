<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * A contract's end before its start, refused ahead of chk_crm_deals_contract (N11). The API answers 422.
 */
final class ContractEndsBeforeStart extends DomainException
{
    private function __construct(public readonly string $dealId)
    {
        parent::__construct(\sprintf('The contract of deal "%s" would end before it starts.', $dealId));
    }

    public static function forDeal(string $dealId): self
    {
        return new self($dealId);
    }
}
