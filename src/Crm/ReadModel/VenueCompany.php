<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\Initials;

/**
 * A venue and its company, if it has one (the venue panel).
 */
final readonly class VenueCompany
{
    /**
     * @param list<CompanyRecordContact> $contacts
     */
    public function __construct(
        public string $venueId,
        public ?string $companyId,
        public ?string $companyName,
        public ?CompanyStage $stage,
        public ?\DateTimeImmutable $since,
        public array $contacts,
    ) {
    }

    public function initials(): string
    {
        return Initials::company($this->companyName ?? '');
    }
}
