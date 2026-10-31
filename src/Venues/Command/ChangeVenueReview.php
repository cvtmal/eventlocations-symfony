<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Saves a review's fields (#25, CMS-8): first name, role (ReviewerRole value), month (`Y-m`)
 * and text; the locale stays. `loadedAt` is the review's row token (RowTokens::sql() of
 * `venue_reviews.updated_at`). An unchanged save changes nothing.
 */
final readonly class ChangeVenueReview implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public string $reviewId,
        public string $loadedAt,
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
        return ['review_id' => $this->reviewId, 'role' => $this->role, 'month' => $this->month];
    }
}
