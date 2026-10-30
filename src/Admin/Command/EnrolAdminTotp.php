<?php

declare(strict_types=1);

namespace App\Admin\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * The user confirmed their first code. The secret is plain here; the handler encrypts it.
 */
final readonly class EnrolAdminTotp implements AuditableCommand
{
    public function __construct(
        public string $userId,
        public string $secret,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('admin_user', $this->userId);
    }

    public function auditSummary(): array
    {
        return ['totp' => 'enrolled'];
    }
}
