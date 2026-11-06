<?php

declare(strict_types=1);

namespace App\Venues\Exception;

use App\Shared\Exception\DomainException;

/**
 * A content folder's `venue.yaml` cannot be read. A message never quotes the file's content.
 */
final class InvalidContentFolder extends DomainException
{
    public static function unreadableFolder(string $key): self
    {
        return new self(\sprintf('%s: venue.yaml cannot be read; the folder was left alone', $key));
    }
}
