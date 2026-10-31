<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Deletes a review (#25, CMS-8). The venue's score and count stay as they are (D17).
 */
final readonly class RemoveVenueReview implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public string $reviewId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['review_id' => $this->reviewId];
    }
}
