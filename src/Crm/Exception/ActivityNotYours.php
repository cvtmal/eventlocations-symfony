<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * Somebody else wrote this timeline entry (D14). The rule holds for `admin` too. The API answers 403.
 */
final class ActivityNotYours extends DomainException
{
    private function __construct(public readonly string $activityId)
    {
        parent::__construct(\sprintf('The activity "%s" belongs to another user.', $activityId));
    }

    public static function forId(string $activityId): self
    {
        return new self($activityId);
    }
}
