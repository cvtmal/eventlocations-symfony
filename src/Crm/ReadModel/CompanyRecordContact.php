<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\Initials;

/**
 * A contact in the Kontakte card of a company (CRM-2).
 */
final readonly class CompanyRecordContact
{
    public function __construct(
        public string $id,
        public ?string $firstName,
        public string $lastName,
        public ?string $position,
    ) {
    }

    public function fullName(): string
    {
        return null === $this->firstName ? $this->lastName : $this->firstName.' '.$this->lastName;
    }

    public function initials(): string
    {
        return Initials::person($this->fullName());
    }
}
