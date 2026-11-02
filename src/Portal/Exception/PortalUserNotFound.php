<?php

declare(strict_types=1);

namespace App\Portal\Exception;

use App\Shared\Exception\DomainException;

/**
 * No manager account has this id. The staff screen answers 404.
 */
final class PortalUserNotFound extends DomainException
{
    private function __construct(public readonly string $userId)
    {
        parent::__construct(\sprintf('There is no portal user "%s".', $userId));
    }

    public static function forId(string $userId): self
    {
        return new self($userId);
    }
}
