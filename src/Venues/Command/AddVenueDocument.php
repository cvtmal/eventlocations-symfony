<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Uploads one PDF behind the venue's last document (#25, CMS-7, D22); the handler returns its
 * id. `sourceFile` is the uploaded file's temporary path (the form checked type and size). The
 * original is stored content-addressed at `cms/<number>/<sha256>.pdf` under MEDIA_ROOT, so the
 * same PDF again is VenueRuleViolation::documentAlreadyUploaded(). A title is required in the
 * default locale. Documents are not on the cards: only the venue is purged (D19).
 */
final readonly class AddVenueDocument implements AuditableCommand
{
    /**
     * @param array<string, string> $titles locale → title
     */
    public function __construct(
        public int $venueNumber,
        public string $sourceFile,
        public array $titles,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['locales' => array_keys($this->titles)];
    }
}
