<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Admin\AuditProbe;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Stands for a command of a public module: it sees Shared's contract and nothing of Admin.
 */
final readonly class AuditProbeCommand implements AuditableCommand
{
    public function __construct(
        public string $id,
        public bool $fail = false,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('probe', $this->id);
    }

    public function auditSummary(): array
    {
        return ['name' => 'Probe', 'tags' => ['a', 'b'], 'count' => 2, 'gone' => null];
    }
}
