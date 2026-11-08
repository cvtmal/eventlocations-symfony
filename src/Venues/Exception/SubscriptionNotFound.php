<?php

declare(strict_types=1);

namespace App\Venues\Exception;

use App\Shared\Exception\DomainException;

/**
 * No paid term has this id (a stale cancellation link of a deleted venue, or a malformed id).
 */
final class SubscriptionNotFound extends DomainException
{
    private function __construct(public readonly string $subscriptionId)
    {
        parent::__construct(\sprintf('There is no subscription "%s".', $subscriptionId));
    }

    public static function forId(string $id): self
    {
        return new self($id);
    }
}
