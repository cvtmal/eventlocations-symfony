<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\CompleteTask;
use App\Crm\Exception\TaskNotFound;
use App\Crm\Repository\TaskRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use Psr\Clock\ClockInterface;

/**
 * A task done twice keeps the first time.
 */
final class CompleteTaskHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(CompleteTask $command): void
    {
        $task = $this->tasks->lock($command->id) ?? throw TaskNotFound::forId($command->id);

        if (!$task->isDone()) {
            $task->complete($this->clock->now());
        }
    }
}
