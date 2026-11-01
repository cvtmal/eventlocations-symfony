<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * The open deal the venue panel shows (D27).
 */
final readonly class VenueOpenDeal
{
    /**
     * @param string $stage the code of its stage
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $stage,
        public ?int $amountRappen,
    ) {
    }
}
