<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Venues\ValueObject\TierLabel;

/**
 * A won deal whose contract ends soon, as the "Verlängerungen" widget shows it (`Main.dc.html`).
 */
final readonly class RenewalRow
{
    public function __construct(
        public string $dealId,
        public string $companyName,
        public ?TierLabel $tier,
        public \DateTimeImmutable $contractEndOn,
        public ?int $amountRappen,
    ) {
    }
}
