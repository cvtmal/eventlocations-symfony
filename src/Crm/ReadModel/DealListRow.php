<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Venues\ValueObject\TierLabel;

final readonly class DealListRow
{
    public function __construct(
        public string $id,
        public string $name,
        public string $companyId,
        public string $companyName,
        public string $stage,
        public ?TierLabel $tier,
        public ?int $amountRappen,
        public ?\DateTimeImmutable $expectedCloseOn,
        public ?string $ownerName,
    ) {
    }
}
