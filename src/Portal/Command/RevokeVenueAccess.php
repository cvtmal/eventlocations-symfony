<?php

declare(strict_types=1);

namespace App\Portal\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * A rejected request and a removed manager both end here. `$via` takes `GrantVenueAccess::VIA_*`.
 */
final readonly class RevokeVenueAccess implements AuditableCommand
{
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
