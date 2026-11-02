<?php

declare(strict_types=1);

namespace App\Portal\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * `$via` tells the review queue from the managers panel in the audit log.
 */
final readonly class GrantVenueAccess implements AuditableCommand
{
    public const string VIA_REVIEW = 'review';
    public const string VIA_PANEL = 'panel';

    public function __construct(
        public string $portalUserId,
        public string $venueId,
        public string $via,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('portal_user', $this->portalUserId);
    }

    public function auditSummary(): array
    {
        return ['venue' => $this->venueId, 'via' => $this->via];
    }
}
