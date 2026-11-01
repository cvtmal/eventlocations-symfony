<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\ChangeDealLostReason;
use App\Crm\Exception\LostReasonRequired;
use App\Crm\Exception\StaleProperty;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\DealRepository;
use App\Shared\Messenger\CommandHandlerInterface;

/**
 * Under the locks, company then deal (D11): a deal that has left lost since the page loaded is stale (D17-b), and a
 * lost deal cannot lose its reason (N9). The stage does not move, so nothing syncs.
 */
final class ChangeDealLostReasonHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly DealRepository $deals,
    ) {
    }

    public function __invoke(ChangeDealLostReason $command): void
    {
        $this->companies->lock($this->deals->companyIdOf($command->dealId));
        $deal = $this->deals->lock($command->dealId);

        if (!$deal->stage()->isLost()) {
            throw StaleProperty::forField('lost_reason');
        }

        $deal->changeLostReason($command->lostReason ?? throw LostReasonRequired::forDeal($deal->id()));
    }
}
