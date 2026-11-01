<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * A venue or contact the deal form offers.
 */
final readonly class DealFormOption
{
    public function __construct(
        public string $id,
        public string $label,
    ) {
    }
}
