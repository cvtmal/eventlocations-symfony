<?php

declare(strict_types=1);

namespace App\Crm\Command;

/**
 * Turns a confirmed location manager, or the manager who submitted a new location, into a `venue_contact` with a
 * timeline entry (POR-7), from `LinkLocationManagerListener`. Not auditable: the approve or the submit is the record,
 * and it has its own audit row.
 */
final readonly class LinkLocationManager
{
    public function __construct(
        public string $venueId,
        public string $venueName,
        public string $email,
        public string $firstName,
        public string $lastName,
        public ?string $phone,
        public string $activityKey,
    ) {
    }
}
