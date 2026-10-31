<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\CompanyOrigin;
use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\Initials;

/**
 * A company record page (CRM-1, CRM-3): the properties in canonical form (ids for the owner, codes for
 * the stage), the owner's name for display, and the Locations and Kontakte cards.
 */
final readonly class CompanyRecord
{
    /**
     * @param list<CompanyRecordVenue>   $venues
     * @param list<CompanyRecordContact> $contacts
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $legalName,
        public ?string $website,
        public ?string $phone,
        public ?string $email,
        public ?string $address,
        public CompanyStage $stage,
        public ?string $ownerId,
        public ?string $ownerName,
        public ?string $notes,
        public CompanyOrigin $origin,
        public \DateTimeImmutable $createdAt,
        public array $venues,
        public array $contacts,
    ) {
    }

    public function initials(): string
    {
        return Initials::company($this->name);
    }
}
