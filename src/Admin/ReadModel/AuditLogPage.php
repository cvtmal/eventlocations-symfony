<?php

declare(strict_types=1);

namespace App\Admin\ReadModel;

final readonly class AuditLogPage
{
    /**
     * @param list<AuditLogRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
