<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Venues\ValueObject\TierLabel;

/**
 * One deal on a company or contact: a row of the Deals card (Company.dc.html) and of the company's Deals tab.
 */
final readonly class DealCardRow
{
    /**
     * @param string $stage the code of its stage
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $stage,
        public ?TierLabel $tier,
        public ?string $venueName,
        public ?int $amountRappen,
        public ?\DateTimeImmutable $contractEndOn,
    ) {
    }
}
