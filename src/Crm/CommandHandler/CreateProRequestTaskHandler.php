<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\CreateProRequestTask;
use App\Crm\Entity\Task;
use App\Crm\Exception\NoTaskAssignee;
use App\Crm\ProRequestTitle;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\TaskRepository;
use App\Crm\TaskAssignee;
use App\Crm\ValueObject\TaskDueDate;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Nobody wrote it, so it has no `createdBy`, like the renewal task. It hangs on the company, so no deal owner applies:
 * the assignee is the company's owner, else the oldest admin, and it is due two days on by Zurich's calendar. A company
 * that has an open task of this title already gets no second one, checked under the company's lock: a manager who
 * presses twice is one request. The listener checks first (P1); this is the race's backstop. The answer says whether a
 * task was written.
 */
final class CreateProRequestTaskHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly TaskRepository $tasks,
        private readonly TaskAssignee $assignees,
        private readonly ProRequestTitle $titles,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(CreateProRequestTask $command): bool
    {
        $company = $this->companies->lock($command->companyId);
        $title = $this->titles->of($command->venueName, $command->email);
        if ($this->tasks->hasOpenTitled($company->id(), $title)) {
            return false;
        }

        $this->em->persist(new Task(
            $command->id,
            $title,
            TaskDueDate::daysAfter($this->clock->now(), CreateProRequestTask::DUE_AFTER_DAYS),
            $this->assignees->among($company->owner()) ?? throw NoTaskAssignee::forProRequest($company->id()),
            null,
            company: $company,
        ));

        return true;
    }
}
