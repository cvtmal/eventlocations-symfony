<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Deletes a document (#25, CMS-7): its row, then after the commit its public PDF and, when no
 * row of any venue uses it any more, its original (PF1).
 */
final readonly class RemoveVenueDocument implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public string $documentId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['document_id' => $this->documentId];
    }
}
