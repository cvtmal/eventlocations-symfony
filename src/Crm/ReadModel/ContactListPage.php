<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

final readonly class ContactListPage
{
    /**
     * @param list<ContactListRow> $rows
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
