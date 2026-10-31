<?php

declare(strict_types=1);

namespace App\Cms\Exception;

use App\Shared\Exception\DomainException;

/**
 * No module declares a text block with this key (#26); the editor answers 404.
 */
final class UnknownContentBlock extends DomainException
{
    private function __construct(public readonly string $key)
    {
        parent::__construct(\sprintf('No text block is declared with the key "%s".', $key));
    }

    public static function forKey(string $key): self
    {
        return new self($key);
    }
}
