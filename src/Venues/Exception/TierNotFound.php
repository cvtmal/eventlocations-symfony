<?php

declare(strict_types=1);

namespace App\Venues\Exception;

use App\Shared\Exception\DomainException;

/**
 * No tier has this code. The seeded Free row is the fallback of every tier question, so a database without it
 * is a broken installation, not a user error.
 */
final class TierNotFound extends DomainException
{
    private function __construct(public readonly string $tierCode)
    {
        parent::__construct(\sprintf('There is no tier "%s".', $tierCode));
    }

    public static function forCode(string $code): self
    {
        return new self($code);
    }
}
