<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Geo\ValueObject\Canton;

/**
 * A canton and the number of its published venues ("Andere suchten auch nach", LP-5).
 */
final readonly class CantonCount
{
    public function __construct(
        public Canton $canton,
        public int $count,
    ) {
    }
}
