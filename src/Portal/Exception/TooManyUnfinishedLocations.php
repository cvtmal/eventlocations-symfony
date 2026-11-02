<?php

declare(strict_types=1);

namespace App\Portal\Exception;

use App\Shared\Exception\DomainException;

/**
 * The account holds as many unfinished locations as one may. The form answers with a plain message.
 */
final class TooManyUnfinishedLocations extends DomainException
{
    private function __construct(public readonly int $limit)
    {
        parent::__construct(\sprintf('An account may hold %d unfinished locations.', $limit));
    }

    public static function forLimit(int $limit): self
    {
        return new self($limit);
    }
}
