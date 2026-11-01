<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * One deal in the global search.
 */
final readonly class DealSearchHit
{
    public function __construct(
        public string $id,
        public string $name,
        public string $companyName,
    ) {
    }
}
