<?php

declare(strict_types=1);

namespace App\Portal\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

final readonly class ChangePortalPassword implements AuditableCommand
{
    public function __construct(
        public string $id,
        public string $plainPassword,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('portal_user', $this->id);
    }

    public function auditSummary(): array
    {
        return ['password' => 'changed'];
    }
}
