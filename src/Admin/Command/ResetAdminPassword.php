<?php

declare(strict_types=1);

namespace App\Admin\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

final readonly class ResetAdminPassword implements AuditableCommand
{
    public function __construct(
        public string $userId,
        public string $plainPassword,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('admin_user', $this->userId);
    }

    public function auditSummary(): array
    {
        return ['password' => 'reset'];
    }
}
