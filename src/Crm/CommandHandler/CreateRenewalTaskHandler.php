<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Admin\Entity\AdminUser;
use App\Admin\Repository\AdminUserRepository;
use App\Crm\Command\CreateRenewalTask;
use App\Crm\Entity\Deal;
use App\Crm\Entity\Task;
use App\Crm\Exception\NoRenewalAssignee;
use App\Crm\Repository\DealRepository;
use App\Crm\Repository\TaskRepository;
use App\Crm\ValueObject\RenewalWindow;
use App\Crm\ValueObject\TaskDueDate;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Translation\EnabledLocale;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Under the deal's lock the scope holds or the deal is skipped: `app:crm:renewal-tasks` read its ids without one.
 * `uk_crm_tasks_renewal` is the backstop. A done task counts, so it is not written again (D24). The assignee is the
 * deal's owner, else the company's, else the oldest admin, each only while active; a deactivated owner is a real
 * case. The task is due 30 days before the end, a default, or today when that has passed. Nobody wrote it, so it has
 * no `createdBy`. The answer says whether a task was written.
 */
final class CreateRenewalTaskHandler implements CommandHandlerInterface
{
    private const int LEAD_DAYS = 30;

    public function __construct(
        private readonly DealRepository $deals,
        private readonly TaskRepository $tasks,
        private readonly AdminUserRepository $users,
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
            $this->dueOn($end, $now),
            $this->assignee($deal),
            null,
            deal: $deal,
            isRenewal: true,
        ));

        return true;
    }

    private function dueOn(\DateTimeImmutable $end, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $lead = $end->modify(\sprintf('-%d days', self::LEAD_DAYS));

        return new \DateTimeImmutable(max($lead->format('Y-m-d'), TaskDueDate::today($now)));
    }

    /**
     * @throws NoRenewalAssignee
     */
    private function assignee(Deal $deal): AdminUser
    {
        foreach ([$deal->owner(), $deal->company()->owner(), $this->users->oldestActiveAdmin()] as $candidate) {
            if (null !== $candidate && $candidate->isActive()) {
                return $candidate;
            }
        }

        throw NoRenewalAssignee::forDeal($deal->id());
    }
}
