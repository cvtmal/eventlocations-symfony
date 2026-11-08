<?php

declare(strict_types=1);

namespace App\Crm;

use App\Crm\Exception\InvalidPropertyValue;
use App\Venues\Entity\Tier;
use App\Venues\Exception\TierNotFound;
use App\Venues\Repository\TierRepository;

/**
 * Turns a tier code from a form or an inline edit into the tier row (F12), like `OwnerResolver` does for an owner:
 * none stays none. The form and the API offer only the codes of `tiers`, so this is the handler's own guard.
 */
final class TierResolver
{
    public function __construct(private readonly TierRepository $tiers)
    {
    }

    /**
     * @throws InvalidPropertyValue when the code names no tier
     */
    public function resolve(?string $code): ?Tier
    {
        if (null === $code) {
            return null;
        }

        try {
            return $this->tiers->byCode($code);
        } catch (TierNotFound) {
            throw InvalidPropertyValue::unknown('tier');
        }
    }
}
