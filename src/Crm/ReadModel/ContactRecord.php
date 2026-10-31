<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\ContactKind;
use App\Crm\ValueObject\Initials;

/**
 * A contact record page (CRM-2, CRM-3): the properties in canonical form (ids for company and owner,
 * the kind's code), the names for display, and the Firma card.
 */
final readonly class ContactRecord
{
    public function __construct(
        public string $id,
        public ?string $firstName,
        public string $lastName,
        public ?string $email,
        public ?string $phone,
        public ?string $position,
        public ContactKind $kind,
        public ?string $companyId,
        public ?string $companyName,
        public ?CompanyStage $companyStage,
        public ?string $ownerId,
        public ?string $ownerName,
        public \DateTimeImmutable $createdAt,
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

    public function companyInitials(): ?string
    {
        return null === $this->companyName ? null : Initials::company($this->companyName);
    }
}
