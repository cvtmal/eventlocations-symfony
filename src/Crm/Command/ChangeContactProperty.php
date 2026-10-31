<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Crm\ValueObject\ContactProperty;
use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * One inline edit on a contact record (D10): `value` is the new value, `expected` the value the
 * page loaded, which is the field's version (D11). Both are raw; the handler canonicalises them.
 * The summary names the field only, never a value.
 */
final readonly class ChangeContactProperty implements AuditableCommand
{
    public function __construct(
        public string $contactId,
        public ContactProperty $property,
        public ?string $value,
        public ?string $expected,
        public string $actingUserId,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('contact', $this->contactId);
    }

    public function auditSummary(): array
    {
        return ['field' => $this->property->value];
    }
}
