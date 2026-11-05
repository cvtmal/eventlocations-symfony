<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * One line of the contact CSV (CRM-6): a contact row with its Location's name (#55).
 */
final readonly class ContactCsvRow
{
    public function __construct(
        public ?string $firstName,
        public string $lastName,
        public string $companyName,
        public ?string $position,
        public ?string $email,
        public ?string $phone,
    ) {
    }
}
