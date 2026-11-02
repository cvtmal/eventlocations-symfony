<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\CreateRenewalTask;
use App\Crm\Entity\Task;
use App\Crm\Exception\NoTaskAssignee;
use App\Crm\Repository\DealRepository;
use App\Crm\Repository\TaskRepository;
use App\Crm\TaskAssignee;
use App\Crm\ValueObject\RenewalWindow;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Translation\EnabledLocale;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Under the deal's lock the scope holds or the deal is skipped: `app:crm:renewal-tasks` read its ids without one.
 * `uk_crm_tasks_renewal` is the backstop. A done task counts, so it is not written again (D24). The assignee is the
 * deal's owner, else the company's, else the oldest admin (`TaskAssignee`). `RenewalWindow::taskDueOn` sets the due
 * date. Nobody wrote it, so it has no `createdBy`. The answer says whether a task was written.
 */
final class CreateRenewalTaskHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly DealRepository $deals,
        private readonly TaskRepository $tasks,
        private readonly TaskAssignee $assignees,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly EnabledLocale $locales,
    ) {
    }

    public function __invoke(CreateRenewalTask $command): bool
    {
        $deal = $this->deals->lock($command->dealId);
        $end = $deal->contractEndOn();
        $now = $this->clock->now();
        if (!$deal->stage()->isWon() || null === $end || !RenewalWindow::at($now)->contains($end)) {
            return false;
        }
        if ($this->deals->isRenewed($deal->id()) || null !== $this->tasks->renewalOf($deal->id())) {
            return false;
        }

        $this->em->persist(new Task(
            $command->id,
            $this->translator->trans('task.renewal_title', ['name' => $deal->name()], 'crm', $this->locales->default()),
            RenewalWindow::taskDueOn($end, $now),
            $this->assignees->among($deal->owner(), $deal->company()->owner()) ?? throw NoTaskAssignee::forDeal($deal->id()),
            null,
            deal: $deal,
            isRenewal: true,
        ));

        return true;
    }
}
