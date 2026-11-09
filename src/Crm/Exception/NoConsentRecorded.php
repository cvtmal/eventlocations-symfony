<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * A withdrawal (F14, `WithdrawConsent`) needs a venue whose Location holds a recorded yes: without one the venue
 * did not come in by consent, and the withdrawal path would delete the wrong thing.
 */
final class NoConsentRecorded extends DomainException
{
    private function __construct(public readonly int $venueNumber)
    {
        parent::__construct(\sprintf('Venue %d has no recorded consent on its Location.', $venueNumber));
    }

    public static function forVenue(int $venueNumber): self
    {
        return new self($venueNumber);
    }
}
