<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Adds a person to a Location's contact card (CRM-2, #55). The id comes from EntityId::generate()
 * before dispatch. The audit row hangs on the Location; the summary names the contact and the
 * fields that were set, never a value: a contact is personal data.
 */
final readonly class CreateContact implements AuditableCommand
{
    public function __construct(
        public string $id,
        public string $companyId,
        public ?string $firstName,
        public string $lastName,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $position = null,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('company', $this->companyId);
    }

    public function auditSummary(): array
    {
        $set = array_keys(array_filter(
            [
                'first_name' => $this->firstName,
                'last_name' => $this->lastName,
                'email' => $this->email,
                'phone' => $this->phone,
                'position' => $this->position,
            ],
            static fn (?string $value): bool => null !== $value && '' !== trim($value),
        ));

        return ['contact' => $this->id, 'fields' => $set];
    }
}
