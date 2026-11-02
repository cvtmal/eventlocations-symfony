<?php

declare(strict_types=1);

namespace App\Portal\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Staff accept a location (#45, POR-13): it goes online through `PublishVenue`, whose audit row keeps subject `venue`.
 */
final readonly class AcceptSubmission implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public string $staffUserId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('portal_submission', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['status' => 'accepted'];
    }
}
