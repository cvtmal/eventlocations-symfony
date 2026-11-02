<?php

declare(strict_types=1);

namespace App\Portal\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

final readonly class RequestVenueAccess implements AuditableCommand
{
    public function __construct(
        public string $portalUserId,
        public int $venueNumber,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('portal_user', $this->portalUserId);
    }

    public function auditSummary(): array
    {
        return ['venue_number' => $this->venueNumber];
    }
}
