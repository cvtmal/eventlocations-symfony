<?php

declare(strict_types=1);

namespace App\Venues;

/**
 * G6 (D18): whether the operator of a venue has confirmed its entry, that is, somebody manages it through `/konto`.
 * Until then the public detail page says «Noch nicht vom Betreiber bestätigt». Portal implements it
 * (`PortalVenueAccessQuery`), because Venues may not name Portal; whatever changes the answer (a claim, staff
 * granting or removing a manager) purges the venue's pages.
 */
interface VenueClaims
{
    public function isConfirmed(string $venueId): bool;
}
