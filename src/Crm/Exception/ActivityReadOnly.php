<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * Nobody writes this entry by hand (D14, P9): it is anonymised, has no staff author, or is of a kind only the
 * system writes. The API refuses such a type with a 422 first and answers 403 for an existing entry.
 */
final class ActivityReadOnly extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function forId(string $activityId): self
    {
        return new self(\sprintf('The activity "%s" is read-only.', $activityId));
    }

    public static function forType(string $type): self
    {
        return new self(\sprintf('Activities of type "%s" are not written by hand.', $type));
    }
}
