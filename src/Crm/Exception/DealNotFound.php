<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * No deal has this id (deleted, or never existed). Pages answer 404.
 */
final class DealNotFound extends DomainException
{
    private function __construct(public readonly string $dealId)
    {
        parent::__construct(\sprintf('There is no deal "%s".', $dealId));
    }

    public static function forId(string $dealId): self
    {
        return new self($dealId);
    }
}
