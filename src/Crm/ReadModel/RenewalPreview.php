<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * The first renewals of the dashboard widget, and how many there are in all.
 */
final readonly class RenewalPreview
{
    /**
     * @param list<RenewalRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $total,
    ) {
    }
}
