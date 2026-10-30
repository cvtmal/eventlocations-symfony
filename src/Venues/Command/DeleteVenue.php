<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Deletes an unpublished venue with all its rows and files (#24, D13). A content venue
 * leaves a tombstone of its key, so the next import does not bring it back unless told to
 * overwrite CMS edits. Name and key are carried for the audit row, which outlives the venue.
 */
final readonly class DeleteVenue implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public string $name,
        public ?string $contentKey = null,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['name' => $this->name, 'content_key' => $this->contentKey];
    }
}
