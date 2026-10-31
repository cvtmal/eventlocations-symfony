<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\ReopenTask;
use App\Crm\Exception\TaskNotFound;
use App\Crm\Repository\TaskRepository;
use App\Shared\Messenger\CommandHandlerInterface;

final class ReopenTaskHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly TaskRepository $tasks,
    ) {
    }

    public function __invoke(ReopenTask $command): void
    {
        $task = $this->tasks->lock($command->id) ?? throw TaskNotFound::forId($command->id);

        if ($task->isDone()) {
            $task->reopen();
        }
    }
}
