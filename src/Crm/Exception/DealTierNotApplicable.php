<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * The deal has no tier to apply (D20): not won, no venue or no tier, the venue no longer the company's, or the venue
 * has the tier already. The offer went away while the form was open.
 */
final class DealTierNotApplicable extends DomainException
{
    private function __construct(public readonly string $dealId)
    {
        parent::__construct(\sprintf('Deal "%s" has no tier to apply.', $dealId));
    }

    public static function forDeal(string $dealId): self
    {
        return new self($dealId);
    }
}
