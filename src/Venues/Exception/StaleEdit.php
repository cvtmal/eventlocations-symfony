<?php

declare(strict_types=1);

namespace App\Venues\Exception;

use App\Shared\Exception\DomainException;

/**
 * Someone saved the venue after this form was loaded (S12): the form's `loadedAt` token no
 * longer matches `venues.updated_at`. Nothing was written; the editor answers 409 and keeps
 * the typed values.
 */
final class StaleEdit extends DomainException
{
    private function __construct(public readonly int $venueNumber)
    {
        parent::__construct(\sprintf('Venue %d was changed after the form was loaded.', $venueNumber));
    }

    public static function forVenue(int $venueNumber): self
    {
        return new self($venueNumber);
    }
}
