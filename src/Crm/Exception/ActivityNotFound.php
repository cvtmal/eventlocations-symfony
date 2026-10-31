<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * No timeline entry has this id (deleted, or never existed). The API answers 404.
 */
final class ActivityNotFound extends DomainException
{
    private function __construct(public readonly string $activityId)
    {
        parent::__construct(\sprintf('There is no activity "%s".', $activityId));
    }

    public static function forId(string $activityId): self
    {
        return new self($activityId);
    }
}
