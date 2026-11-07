<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\ApplyDealTier;
use App\Crm\Exception\DealTierNotApplicable;
use App\Crm\Exception\StaleProperty;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\DealRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\ChangeVenueTier;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Under the locks, company then deal, with the venue's last in `ChangeVenueTier` (D11). The dispatch is unstamped on
 * purpose, like `CreateVenueHandler`'s: both commands commit together, or neither, and the venue's stamped purges wait
 * for the end of this root dispatch, so they run after the commit (D19). Tiers are compared by code, the name a tier
 * keeps for good (F12).
 */
final class ApplyDealTierHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly DealRepository $deals,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(ApplyDealTier $command): void
    {
        $companyId = $this->deals->companyIdOf($command->dealId);
        $company = $this->companies->lock($companyId);
        $deal = $this->deals->lock($command->dealId);

        $venue = $deal->venue();
        $tier = $deal->tier();
        if (null === $venue || null === $tier || !$deal->stage()->isWon()) {
            throw DealTierNotApplicable::forDeal($deal->id());
        }
        if ($tier->code() === $venue->tier()->code() || $company->venue()?->id() !== $venue->id()) {
            throw DealTierNotApplicable::forDeal($deal->id());
        }
        if ($command->expected !== $venue->tier()->code()) {
            throw StaleProperty::forField('tier');
        }

        $this->commandBus->dispatch(new ChangeVenueTier($venue->number(), $tier->code()));
    }
}
