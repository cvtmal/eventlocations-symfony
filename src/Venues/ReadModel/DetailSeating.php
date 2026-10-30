<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Venues\ValueObject\Seating;

/**
 * One capacity line of a room's "Rauminfos" dialog (DP-8): seating type and its range. Only
 * seatings with a max are listed.
 */
final readonly class DetailSeating
{
    public function __construct(
        public Seating $seating,
        public ?int $min,
        public int $max,
    ) {
    }
}
