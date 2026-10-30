<?php

declare(strict_types=1);

namespace App\Admin\Command;

use App\Admin\ValueObject\AdminRole;
use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Creates a staff account. The plain password goes straight to the handler's hasher; it never reaches the audit summary.
 */
final readonly class CreateAdminUser implements AuditableCommand
{
    public function __construct(
        public string $id,
        public string $email,
        public string $displayName,
        public AdminRole $role,
        public string $plainPassword,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('admin_user', $this->id);
    }

    public function auditSummary(): array
    {
        return ['email' => mb_strtolower($this->email), 'display_name' => $this->displayName, 'role' => $this->role->value];
    }
}
