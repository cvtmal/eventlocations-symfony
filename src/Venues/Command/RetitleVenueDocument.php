<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Sets a document's titles (#25, CMS-7). Only the locales in `titles` change; an empty title of
 * a non-default locale removes its row, the default locale keeps a title. `loadedAt` is the
 * document's row token (RowTokens::sql() of `venue_documents.updated_at`).
 */
final readonly class RetitleVenueDocument implements AuditableCommand
{
    /**
     * @param array<string, string> $titles locale → title
     */
    public function __construct(
        public int $venueNumber,
        public string $documentId,
        public string $loadedAt,
        public array $titles,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['document_id' => $this->documentId, 'locales' => array_keys($this->titles)];
    }
}
