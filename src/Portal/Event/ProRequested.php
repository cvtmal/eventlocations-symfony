<?php

declare(strict_types=1);

namespace App\Portal\Event;

/**
 * A manager asked for the Pro plan of a location in `/konto` ("Pro anfragen", F12). It carries plain values, so the
 * CRM, which turns it into a task for staff, reads nothing else of Portal. No payment is taken: staff invoice by hand.
 */
final readonly class ProRequested
{
    public function __construct(
        public string $venueId,
        public string $venueName,
        public string $email,
        public string $firstName,
        public string $lastName,
    ) {
    }
}
