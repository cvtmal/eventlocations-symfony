<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

final readonly class TaskListPage
{
    /**
     * @param list<TaskRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $total,
        public int $page,
        public int $perPage,
        public int $lastPage,
    ) {
    }
}
