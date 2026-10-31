<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * The first tasks of a dashboard widget, and how many there are in all.
 */
final readonly class TaskPreview
{
    /**
     * @param list<TaskRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $total,
    ) {
    }
}
