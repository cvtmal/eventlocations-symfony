<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * The Deals card: its first rows and how many there are.
 */
final readonly class DealCard
{
    /**
     * @param list<DealCardRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $total,
    ) {
    }
}
