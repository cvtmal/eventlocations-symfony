<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Puts the venue's documents in this order (#25, D11, D22). The list must hold exactly the
 * venue's documents, else StaleEdit.
 */
final readonly class ReorderVenueDocuments implements AuditableCommand
{
    /**
     * @param list<string> $documentIds
     */
    public function __construct(
        public int $venueNumber,
        public array $documentIds,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['document_ids' => $this->documentIds];
    }
}
