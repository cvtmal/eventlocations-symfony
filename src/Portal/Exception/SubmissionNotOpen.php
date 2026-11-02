<?php

declare(strict_types=1);

namespace App\Portal\Exception;

use App\Shared\Exception\DomainException;

/**
 * Staff can only decide a location that waits for review: someone else decided first, or nothing was sent in.
 */
final class SubmissionNotOpen extends DomainException
{
    private function __construct(public readonly int $venueNumber)
    {
        parent::__construct(\sprintf('Location %d does not wait for a review.', $venueNumber));
    }

    public static function forNumber(int $number): self
    {
        return new self($number);
    }
}
