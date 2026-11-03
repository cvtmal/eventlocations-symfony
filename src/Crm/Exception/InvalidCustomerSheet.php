<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * The owner's customer sheet (#48) cannot be read as a whole. A message never quotes a cell: the file
 * holds real contact data, and these lines reach the console.
 */
final class InvalidCustomerSheet extends DomainException
{
    public static function unreadable(string $path): self
    {
        return new self(\sprintf('The customer sheet "%s" cannot be read.', $path));
    }

    /**
     * @param list<string> $columns
     */
    public static function missingColumns(array $columns): self
    {
        return new self('The customer sheet lacks the column(s): '.implode(', ', array_map(static fn (string $column): string => '"'.$column.'"', $columns)));
    }

    public static function malformedRow(int $row): self
    {
        return new self(\sprintf('Row %d of the customer sheet has the wrong number of fields.', $row));
    }
}
