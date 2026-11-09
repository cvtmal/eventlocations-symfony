<?php

declare(strict_types=1);

namespace App\Inquiries\Exception;

use App\Shared\Exception\DomainException;

/**
 * A brief to several venues cannot go out as the guest chose it: a venue no longer takes briefs (gone, unpublished,
 * no inquiry address, opted out, today's cap reached) or the number of venues is outside 2 to 5. The whole brief is
 * refused, so only the venues the guest saw named get mail.
 */
final class BriefNotAccepted extends DomainException
{
    public static function forVenue(string $venueId): self
    {
        return new self(\sprintf('Venue "%s" does not accept a brief now.', $venueId));
    }

    public static function venueCount(int $count): self
    {
        return new self(\sprintf('A brief goes to 2 to 5 venues, not %d.', $count));
    }
}
