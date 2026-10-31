<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Adds a review to the venue (#25, CMS-8); the handler returns its id. `role` is a
 * ReviewerRole value, `month` is `Y-m`, stored as the first of the month. Score, count and
 * highlights stay #24's RateVenue (D17); only the venue is purged (D19).
 */
final readonly class AddVenueReview implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public string $locale,
        public string $firstName,
        public string $role,
        public string $month,
        public string $text,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['locale' => $this->locale, 'role' => $this->role, 'month' => $this->month];
    }
}
