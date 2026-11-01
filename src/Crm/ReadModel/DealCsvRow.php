<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\LostReason;
use App\Venues\ValueObject\Tier;

/**
 * One line of the deal CSV (S13).
 */
final readonly class DealCsvRow
{
    public function __construct(
        public string $name,
        public string $companyName,
        public ?string $venueName,
        public string $stage,
        public ?LostReason $lostReason,
        public ?Tier $tier,
        public ?int $amountRappen,
        public ?\DateTimeImmutable $contractStartOn,
        public ?\DateTimeImmutable $contractEndOn,
        public ?\DateTimeImmutable $expectedCloseOn,
        public ?string $ownerName,
    ) {
    }
}
