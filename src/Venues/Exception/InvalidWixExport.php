<?php

declare(strict_types=1);

namespace App\Venues\Exception;

use App\Shared\Exception\DomainException;

/**
 * The owner's Wix CSV, or one of its rows, cannot be turned into a content folder. A message
 * never quotes a cell: the file holds real contact data, and these lines reach the console.
 */
final class InvalidWixExport extends DomainException
{
    /**
     * @param list<string> $columns
     */
    public static function missingColumns(array $columns): self
    {
        return new self('The CSV lacks the column(s): '.implode(', ', $columns));
    }

    public static function unreadable(string $path): self
    {
        return new self(\sprintf('"%s" cannot be read.', $path));
    }

    public static function malformedRow(int $row): self
    {
        return new self(\sprintf('Row %d has the wrong number of fields.', $row));
    }

    public static function unresolvedRow(string $reason): self
    {
        return new self($reason);
    }

    public static function unreadableFolder(string $key): self
    {
        return new self(\sprintf('%s: venue.yaml cannot be read; the folder was left alone', $key));
    }
}
