<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * The Location of a new deal, with its Eintrag, which the deal takes (N5, #56 D7).
 */
final readonly class DealFormCompany
{
    public function __construct(
        public string $id,
        public string $name,
        public ?DealFormOption $venue,
    ) {
    }
}
