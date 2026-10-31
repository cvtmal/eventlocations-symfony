<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\ContactKind;
use App\Crm\ValueObject\Initials;

final readonly class ContactListRow
{
    public function __construct(
        public string $id,
        public ?string $firstName,
        public string $lastName,
        public ?string $position,
        public ContactKind $kind,
        public ?string $companyId,
        public ?string $companyName,
        public ?string $email,
        public ?string $ownerName,
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
