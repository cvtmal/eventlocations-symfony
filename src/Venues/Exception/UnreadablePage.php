<?php

declare(strict_types=1);

namespace App\Venues\Exception;

use App\Shared\Exception\DomainException;

/**
 * A fetched page the importer will not parse: reading it would cost far more than any venue's page is worth.
 */
final class UnreadablePage extends DomainException
{
    public static function tooDeep(int $levels): self
    {
        return new self(\sprintf('the page nests elements more than %d levels deep', $levels));
    }
}
