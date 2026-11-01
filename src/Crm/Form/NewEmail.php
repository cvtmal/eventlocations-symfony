<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ValueObject\RecordKind;

/**
 * A mail as the composer sends it, checked for shape; `subject` and `body` are canonical, `cc` is a canonical address.
 * `contactId` is the contact a company's mail goes to, null for the company's own address.
 */
final readonly class NewEmail
{
    public function __construct(
        public RecordKind $kind,
        public string $recordId,
        public ?string $contactId,
        public ?string $cc,
        public string $subject,
        public string $body,
    ) {
    }
}
