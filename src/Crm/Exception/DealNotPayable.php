<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * The deal has no payment to record (G9): not won, no venue, no tier or a tier without a price, or the venue no longer
 * the company's. The offer went away while the form was open.
 */
final class DealNotPayable extends DomainException
{
    private function __construct(public readonly string $dealId)
    {
        parent::__construct(\sprintf('Deal "%s" has no payment to record.', $dealId));
    }

    public static function forDeal(string $dealId): self
    {
        return new self($dealId);
    }
}
