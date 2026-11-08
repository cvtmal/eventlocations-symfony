<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\SuppressionReason;

/**
 * Why and since when a Location is suppressed; `whole` is false when only one of its addresses is on the list.
 */
final readonly class SuppressionState
{
    public function __construct(
        public SuppressionReason $reason,
        public \DateTimeImmutable $since,
        public bool $whole,
    ) {
    }
}
