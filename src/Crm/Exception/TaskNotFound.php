<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * No task has this id: it went with its company or contact (D8), or never existed.
 */
final class TaskNotFound extends DomainException
{
    private function __construct(public readonly string $taskId)
    {
        parent::__construct(\sprintf('There is no task "%s".', $taskId));
    }

    public static function forId(string $taskId): self
    {
        return new self($taskId);
    }
}
