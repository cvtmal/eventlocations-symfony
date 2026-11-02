<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

final readonly class PortalAccountPage
{
    /**
     * @param list<PortalAccountRow> $rows
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
