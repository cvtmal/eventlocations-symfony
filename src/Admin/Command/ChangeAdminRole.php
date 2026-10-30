<?php

declare(strict_types=1);

namespace App\Admin\Command;

use App\Admin\ValueObject\AdminRole;
use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

final readonly class ChangeAdminRole implements AuditableCommand
{
    public function __construct(
        public string $userId,
        public AdminRole $role,
        public string $actingUserId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('admin_user', $this->userId);
    }

    public function auditSummary(): array
    {
        return ['role' => $this->role->value];
    }
}
