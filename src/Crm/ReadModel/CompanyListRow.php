<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\Initials;

final readonly class CompanyListRow
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $website,
        public CompanyStage $stage,
        public ?string $ownerName,
        public int $venueCount,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public function initials(): string
    {
        return Initials::company($this->name);
    }
}
