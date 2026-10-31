<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\ContactKind;

/**
 * One line of the contact CSV (CRM-6).
 */
final readonly class ContactCsvRow
{
    public function __construct(
        public ?string $firstName,
        public string $lastName,
        public ContactKind $kind,
        public ?string $companyName,
        public ?string $position,
        public ?string $email,
        public ?string $phone,
        public ?string $ownerName,
    ) {
    }
}
