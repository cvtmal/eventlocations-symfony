<?php

declare(strict_types=1);

namespace App\Inquiries\Exception;

use App\Shared\Exception\DomainException;

/**
 * The venue is gone, not published, or has no inquiry address.
 */
final class InquiryNotAccepted extends DomainException
{
    public static function forVenue(string $venueId): self
    {
        return new self(\sprintf('Venue "%s" does not accept inquiries.', $venueId));
    }
}
