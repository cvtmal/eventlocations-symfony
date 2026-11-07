<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * A manager asked for the Pro plan in `/konto` (F12): the reminder for staff to get in touch and invoice, on the
 * venue's company. The app takes no payment and holds no invoice, so this is a to-do and nothing else. The venue's name
 * and the manager's address go into the task's title (`ProRequestTitle`); the audit summary holds neither. The id
 * comes from EntityId::generate() before dispatch. The handler answers whether it wrote a task: a company with an open
 * task of this title gets no second one.
 */
final readonly class CreateProRequestTask implements AuditableCommand
{
    public const int DUE_AFTER_DAYS = 2;

    public function __construct(
        public string $id,
        public string $companyId,
        public string $venueName,
        public string $email,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('task', $this->id);
    }

    public function auditSummary(): array
    {
        return ['on' => 'company', 'pro_request' => true];
    }
}
