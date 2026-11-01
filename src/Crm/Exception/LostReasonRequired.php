<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * A deal moved to a lost stage needs a reason (N9). A CHECK cannot read the stage rows, so the handler is the guard.
 */
final class LostReasonRequired extends DomainException
{
    private function __construct(public readonly string $dealId)
    {
        parent::__construct(\sprintf('The deal "%s" needs a reason to be lost.', $dealId));
    }

    public static function forDeal(string $dealId): self
    {
        return new self($dealId);
    }
}
