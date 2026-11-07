<?php

declare(strict_types=1);

namespace App\Venues\Command;

/**
 * "Neue Location" in the CMS (#24): an unpublished venue with a name, a tier (its code) and a maximum
 * capacity, no address. The handler returns the new venue's number. Not auditable itself:
 * the number exists only after the insert, so the handler dispatches RecordVenueCreated,
 * which carries it, inside the same transaction.
 */
final readonly class CreateVenue
{
    public function __construct(
        public string $name,
        public string $tierCode,
        public int $capacityMax,
    ) {
    }
}
