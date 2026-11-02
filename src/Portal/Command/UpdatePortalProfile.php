<?php

declare(strict_types=1);

namespace App\Portal\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

final readonly class UpdatePortalProfile implements AuditableCommand
{
    public function __construct(
        public string $id,
        public string $firstName,
        public string $lastName,
        public ?string $phone,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('portal_user', $this->id);
    }

    public function auditSummary(): array
    {
        return ['first_name' => $this->firstName, 'last_name' => $this->lastName, 'phone' => $this->phone];
    }
}
