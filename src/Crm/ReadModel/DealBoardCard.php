<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\Initials;
use App\Crm\ValueObject\LostReason;
use App\Venues\ValueObject\Tier;

/**
 * A deal on the board. `closedOn` is the Zurich day it was won or lost.
 */
final readonly class DealBoardCard
{
    public function __construct(
        public string $id,
        public string $name,
        public string $companyName,
        public ?Tier $tier,
        public ?int $amountRappen,
        public ?string $expectedCloseOn,
        public ?string $closedOn,
        public ?LostReason $lostReason,
        public ?string $ownerName,
    ) {
    }

    public function ownerInitials(): ?string
    {
        return null === $this->ownerName ? null : Initials::person($this->ownerName);
    }
}
