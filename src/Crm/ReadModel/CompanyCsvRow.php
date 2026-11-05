<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\CompanyOrigin;
use App\Crm\ValueObject\CompanyStage;

/**
 * One line of the company CSV (CRM-6). The notes stay out: they are free text about the relationship,
 * and a mailing list has no use for them.
 */
final readonly class CompanyCsvRow
{
    public function __construct(
        public string $name,
        public ?string $legalName,
        public ?string $website,
        public ?string $phone,
        public ?string $email,
        public ?string $address,
        public CompanyStage $stage,
        public ?string $ownerName,
        public ?string $venueName,
        public CompanyOrigin $origin,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
