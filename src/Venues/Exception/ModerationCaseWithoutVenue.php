<?php

declare(strict_types=1);

namespace App\Venues\Exception;

use App\Shared\Exception\DomainException;

/**
 * The complaint names no venue of the site (sent from the footer, or the venue was deleted), so there is no entry
 * to take offline (G2).
 */
final class ModerationCaseWithoutVenue extends DomainException
{
    public static function forId(string $id): self
    {
        return new self(\sprintf('The moderation case "%s" names no venue.', $id));
    }
}
