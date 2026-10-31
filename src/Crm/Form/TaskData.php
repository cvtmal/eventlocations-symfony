<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\Command\CreateTask;
use App\Crm\ValueObject\TaskDueDate;

/**
 * "Aufgabe erfassen" on the task list (CRM-5): a title, a due date and an assignee, on no record. The rules are
 * `PropertyConstraints`', set by `TaskType`, so the form refuses what the composer's endpoint refuses.
 */
final class TaskData
{
    public ?string $title = null;
    public ?string $dueOn = null;
    public ?string $assignee = null;

    public function toCommand(string $id, string $actingUserId): CreateTask
    {
        return new CreateTask(
            $id,
            (string) $this->title,
            TaskDueDate::parse($this->dueOn) ?? throw new \LogicException('Validate the form before building its command.'),
            (string) $this->assignee,
            $actingUserId,
        );
    }
}
