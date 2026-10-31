<?php

declare(strict_types=1);

namespace App\Cms\Exception;

use App\Shared\Exception\DomainException;

/**
 * No landing page has this key prefix, or it has no published venue (#26); the editor answers 404.
 */
final class UnknownLandingPage extends DomainException
{
    private function __construct(public readonly string $keyPrefix)
    {
        parent::__construct(\sprintf('There is no landing page "%s".', $keyPrefix));
    }

    public static function forKey(string $keyPrefix): self
    {
        return new self($keyPrefix);
    }
}
