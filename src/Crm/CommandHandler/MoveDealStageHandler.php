<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Admin\Entity\AdminUser;
use App\Crm\Command\MoveDealStage;
use App\Crm\CompanyStageSync;
use App\Crm\Entity\Activity;
use App\Crm\Entity\ActivityCompany;
use App\Crm\Entity\ActivityDeal;
use App\Crm\Entity\ActivityType;
use App\Crm\Entity\Deal;
use App\Crm\Entity\DealStage;
use App\Crm\Exception\LostReasonRequired;
use App\Crm\Exception\StaleProperty;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\DealRepository;
use App\Crm\Repository\DealStageRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Uid\EntityId;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Under the locks, company then deal (D11): the same target is a no-op, another stage than the expected one is stale
 * (D17-b), lost needs a reason (N9). Going back from won or lost clears `closedAt` and the reason. The stage change
 * goes on the deal's and the company's timeline, never a contact's, so an erasure has nothing to do with it (D28).
 * Its title and payload are a snapshot: the company's timeline outlives a deleted deal (D17-d).
 */
final class MoveDealStageHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly DealRepository $deals,
        private readonly DealStageRepository $stages,
        private readonly CompanyStageSync $stageSync,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(MoveDealStage $command): void
    {
        $companyId = $this->deals->companyIdOf($command->dealId);
        $company = $this->companies->lock($companyId);
        $deal = $this->deals->lock($command->dealId);
        $from = $deal->stage();

        if ($command->stage === $from->code()) {
            return;
        }
        if ($command->expected !== $from->code()) {
            throw StaleProperty::forField('stage');
        }
        $target = $this->stages->get($command->stage);
        if ($target->isLost() && null === $command->lostReason) {
            throw LostReasonRequired::forDeal($deal->id());
        }

        $now = $this->clock->now();
        $deal->moveTo($target, $command->lostReason, $now);

        $activity = $this->stageChange($deal, $from, $target, $command->actingUserId, $now);
        $this->em->persist($activity);
        $this->em->persist(new ActivityDeal($activity, $deal));
        $this->em->persist(new ActivityCompany($activity, $company));

        $this->em->flush();
        $this->stageSync->afterDealChange($companyId, $from->isWon());
    }

    private function stageChange(Deal $deal, DealStage $from, DealStage $to, string $actingUserId, \DateTimeImmutable $at): Activity
    {
        return new Activity(
            EntityId::generate(),
            $this->em->find(ActivityType::class, ActivityType::DEAL_STAGE_CHANGED) ?? throw new \LogicException('The migration seeds this type.'),
            $at,
            $this->em->getReference(AdminUser::class, $actingUserId),
            $deal->name(),
            null,
            ['deal_name' => $deal->name(), 'from' => $from->code(), 'to' => $to->code()],
        );
    }
}
