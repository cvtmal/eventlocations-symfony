<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * A task on the task list or a record's Aufgaben card. `dueOn` is `YYYY-MM-DD`; `crm_task_due()` turns it into its pill.
 */
final readonly class TaskRow
{
    public function __construct(
        public string $id,
        public string $title,
        public string $dueOn,
        public bool $done,
        public string $assigneeName,
        public ?string $companyId,
        public ?string $companyName,
        public ?string $contactId,
        public ?string $contactName,
    ) {
    }
}
