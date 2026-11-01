<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * Only a won deal with a contract end can be renewed, and only once (D21); `uk_crm_deals_renewed_from_id` is the
 * backstop.
 */
final class DealNotRenewable extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function noRunningContract(string $dealId): self
    {
        return new self(\sprintf('Deal "%s" has no won contract to renew.', $dealId));
    }

    public static function alreadyRenewed(string $dealId): self
    {
        return new self(\sprintf('Deal "%s" was renewed already.', $dealId));
    }
}
