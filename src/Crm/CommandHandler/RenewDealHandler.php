<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\RenewDeal;
use App\Crm\Entity\Deal;
use App\Crm\Entity\DealStage;
use App\Crm\Exception\DealNotRenewable;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\DealRepository;
use App\Crm\Repository\DealStageRepository;
use App\Crm\Repository\TaskRepository;
use App\Crm\ValueObject\ContractPeriod;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Translation\EnabledLocale;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Under the locks, company then deal (D11). The copy takes company, venue, tier, amount and owner, never
 * `closedAt` or `lostReason`: a renewal starts over at `new`, expected to close when the old contract ends. The old
 * deal's open renewal task is done with it, in one transaction (D21). A new open deal changes no derived stage.
 */
final class RenewDealHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly DealRepository $deals,
        private readonly DealStageRepository $stages,
        private readonly TaskRepository $tasks,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly EnabledLocale $locales,
    ) {
    }

    public function __invoke(RenewDeal $command): void
    {
        $company = $this->companies->lock($this->deals->companyIdOf($command->dealId));
        $old = $this->deals->lock($command->dealId);
        $end = $old->contractEndOn();
        if (!$old->stage()->isWon() || null === $end) {
            throw DealNotRenewable::noRunningContract($old->id());
        }
        if ($this->deals->isRenewed($old->id())) {
            throw DealNotRenewable::alreadyRenewed($old->id());
        }

        $period = ContractPeriod::after($old->contractStartOn(), $end);
        $renewal = new Deal(
            $command->id,
            // The year goes in as text: as a number the catalogue's ICU would group it, 2'027.
            $this->translator->trans('deal.renew.name', ['year' => $period->end->format('Y')], 'crm', $this->locales->default()),
            $company,
            $this->stages->get(DealStage::NEW),
            $old->venue(),
            $old->tier(),
            $old->amountRappen(),
            $period->start,
            $period->end,
            $end,
            $old->owner(),
            $old,
        );
        $this->deals->add($renewal);

        $task = $this->tasks->renewalOf($old->id());
        if (null !== $task && !$task->isDone()) {
            $task->complete($this->clock->now());
        }
    }
}
