<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Saves the whole edit dialog of one contact row on a Location's card (#55). The caller dispatches
 * it only when something changed (the no-op rule) and names the changed fields, which the audit
 * summary lists without their values.
 */
final readonly class ChangeContact implements AuditableCommand
{
    /**
     * @param list<string> $fields the changed fields (`first_name`, `last_name`, `email`, `phone`, `position`)
     */
    public function __construct(
        public string $contactId,
        public string $companyId,
        public ?string $firstName,
        public string $lastName,
        public ?string $email,
        public ?string $phone,
        public ?string $position,
        public array $fields,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('company', $this->companyId);
    }

    public function auditSummary(): array
    {
        return ['contact' => $this->contactId, 'fields' => $this->fields];
    }
}
