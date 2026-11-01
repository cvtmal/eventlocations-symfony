<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ValueObject\RecordKind;

/**
 * What the composer asks a draft for, checked for shape: the ids are UUIDs and `kind` is one that can send. `contactId`
 * is the contact a company's mail goes to, null for the company's own address.
 */
final readonly class EmailDraftRequest
{
    public function __construct(
        public RecordKind $kind,
        public string $recordId,
        public string $templateId,
        public ?string $contactId,
    ) {
    }
}
