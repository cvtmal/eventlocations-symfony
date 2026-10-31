<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * A staff account that can own a company or contact (S13), for the owner selects and filters.
 */
final readonly class OwnerOption
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $active,
    ) {
    }
}
