<?php

declare(strict_types=1);

namespace App\Geo\Exception;

use App\Shared\Exception\DomainException;

/**
 * The towns CSV cannot be read or does not match the expected format.
 */
final class InvalidTownDataset extends DomainException
{
    public static function unreadable(string $path): self
    {
        return new self(\sprintf('The towns dataset "%s" cannot be read.', $path));
    }

    public static function unexpectedHeader(string $header): self
    {
        return new self(\sprintf('The towns dataset has an unexpected header "%s".', $header));
    }

    public static function invalidRow(int $line, string $reason): self
    {
        return new self(\sprintf('The towns dataset has an invalid row on line %d: %s.', $line, $reason));
    }
}
