<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Creates a company by hand (CRM-1). The id comes from EntityId::generate() before dispatch, so the
 * caller can redirect to the record. Values are canonicalised by the handler; the summary names
 * the fields that were set, never their values.
 */
final readonly class CreateCompany implements AuditableCommand
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $website = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $ownerId = null,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('company', $this->id);
    }

    public function auditSummary(): array
    {
        return ['fields' => array_keys(array_filter(
            ['name' => $this->name, 'website' => $this->website, 'phone' => $this->phone, 'email' => $this->email, 'owner' => $this->ownerId],
            static fn (?string $value): bool => null !== $value && '' !== trim($value),
        ))];
    }
}
