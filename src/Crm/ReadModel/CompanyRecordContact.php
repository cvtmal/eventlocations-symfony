<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\Initials;

/**
 * A contact row in the Kontakte card of a Location (CRM-2, #55). Only the company record reads `email` and `phone`, for
 * the mail's recipients, the card's lines and the edit dialog: `DealRecordQuery` and `VenueCompanyQuery` build this too
 * and need neither.
 */
final readonly class CompanyRecordContact
{
    public function __construct(
        public string $id,
        public ?string $firstName,
        public string $lastName,
        public ?string $position,
        public ?string $email = null,
        public ?string $phone = null,
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
