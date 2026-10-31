<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Crm\ValueObject\ContactKind;
use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Creates a contact (CRM-2), optionally at a company. The id comes from EntityId::generate() before
 * dispatch. The summary names the fields that were set, never a value: a contact is personal data.
 */
final readonly class CreateContact implements AuditableCommand
{
    public function __construct(
        public string $id,
        public ?string $firstName,
        public string $lastName,
        public ContactKind $kind,
        public ?string $companyId = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $position = null,
        public ?string $ownerId = null,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('contact', $this->id);
    }

    public function auditSummary(): array
    {
        $set = array_keys(array_filter(
            [
                'first_name' => $this->firstName,
                'last_name' => $this->lastName,
                'company' => $this->companyId,
                'email' => $this->email,
                'phone' => $this->phone,
                'position' => $this->position,
                'owner' => $this->ownerId,
            ],
            static fn (?string $value): bool => null !== $value && '' !== trim($value),
        ));

        return ['fields' => $set, 'kind' => $this->kind->value];
    }
}
