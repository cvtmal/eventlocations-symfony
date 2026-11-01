<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\DeleteDeal;
use App\Crm\CompanyStageSync;
use App\Crm\Repository\ActivityRepository;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\DealRepository;
use App\Shared\Messenger\CommandHandlerInterface;

/**
 * The database does the cascade (contact links, timeline links, tasks; a renewal stays, SET NULL). The handler then
 * deletes the entries the deal was the last object of, as DeleteCompanyHandler does, and brings the company's stage
 * up to date. The delete is SQL, so nothing waits for a flush (N11).
 */
final class DeleteDealHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly DealRepository $deals,
        private readonly ActivityRepository $activities,
        private readonly CompanyStageSync $stageSync,
    ) {
    }

    public function __invoke(DeleteDeal $command): void
    {
        $companyId = $this->deals->companyIdOf($command->dealId);
        $this->companies->lock($companyId);
        $deal = $this->deals->lock($command->dealId);
        $wasWon = $deal->stage()->isWon();
        $activityIds = $this->activities->idsLinkedToDeal($deal->id());

        $this->deals->delete($deal->id());
        $this->activities->deleteUnlinked($activityIds);
        $this->stageSync->afterDealChange($companyId, $wasWon);
    }
}
