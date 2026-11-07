<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Ends a preview link of the entry for good (G5): the link answers 410 from now on. A link that is
 * already revoked keeps its first revocation time; an id that is no link of this venue is a
 * `VenueRowNotFound`.
 */
final readonly class RevokeVenuePreview implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public string $previewId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    /**
     * Nothing that could rebuild the link: the token derives from the id.
     */
    public function auditSummary(): array
    {
        return ['preview' => 'revoked'];
    }
}
