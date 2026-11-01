<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ValueObject\LostReason;

/**
 * One move on the board as the island sends it: `{"stage", "expected", "lostReason"}` (D17-a). `expected` is the stage
 * the card showed (D17-b), the reason is what the lost prompt chose.
 */
final readonly class StageMove
{
    public function __construct(
        public string $stage,
        public string $expected,
        public ?LostReason $lostReason,
    ) {
    }
}
