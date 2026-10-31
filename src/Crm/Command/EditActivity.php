<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Rewrites your own timeline entry (D14). No stale check: an entry has a single author.
 */
final readonly class EditActivity implements AuditableCommand
{
    public function __construct(
        public string $id,
        public ?string $body,
        public \DateTimeImmutable $occurredAt,
        public string $actingUserId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('activity', $this->id);
    }

    public function auditSummary(): array
    {
        return ['action' => 'edited'];
    }
}
