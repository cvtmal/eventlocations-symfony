<?php

declare(strict_types=1);

namespace App\Venues\Exception;

use App\Shared\Exception\DomainException;

/**
 * No complaint has this id (G2). The staff case page answers 404.
 */
final class ModerationCaseNotFound extends DomainException
{
    public static function forId(string $id): self
    {
        return new self(\sprintf('There is no moderation case "%s".', $id));
    }
}
