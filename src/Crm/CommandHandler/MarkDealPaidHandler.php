<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\MarkDealPaid;
use App\Crm\Exception\DealNotPayable;
use App\Crm\Exception\StaleProperty;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\DealRepository;
use App\Crm\ValueObject\PaidTerm;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\RecordSubscriptionPayment;
use App\Venues\ReadModel\SubscriptionTermQuery;
use App\Venues\ValueObject\TermDay;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Under the locks, company then deal, with the venue's last in `RecordSubscriptionPayment` (D11). The dispatch is
 * unstamped on purpose, like `CreateVenueHandler`'s: both commands commit together, or neither, and the venue's
 * stamped purges and funnel step wait for the end of this root dispatch, so they run after the commit (D19). A term
 * the venue's terms refuse (an overlap) arrives as the nested `SubscriptionRefused`.
 */
final class MarkDealPaidHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly DealRepository $deals,
        private readonly SubscriptionTermQuery $terms,
        private readonly MessageBusInterface $commandBus,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(MarkDealPaid $command): void
    {
        $companyId = $this->deals->companyIdOf($command->dealId);
        $company = $this->companies->lock($companyId);
        $deal = $this->deals->lock($command->dealId);
        $venue = $deal->venue();
        $tier = $deal->tier();
        if (null === $venue || null === $tier || !$deal->stage()->isWon() || $tier->priceRappen() <= 0 || $company->venue()?->id() !== $venue->id()) {
            throw DealNotPayable::forDeal($deal->id());
        }
        $paidUntil = $this->terms->paidUntil($venue->id());
        if ($command->expected !== ($paidUntil?->format('Y-m-d') ?? '')) {
            throw StaleProperty::forField('subscription');
        }
        $term = PaidTerm::forDeal($deal->contractStartOn(), $deal->contractEndOn(), $paidUntil, TermDay::of($this->clock->now()));

        $this->commandBus->dispatch(new RecordSubscriptionPayment($venue->number(), $tier->code(), $term->startsOn, $term->endsOn, $command->invoiceRef, $command->amountRappen ?? $deal->amountRappen() ?? $tier->priceRappen(), $command->note));

        if (null === $deal->contractStartOn()) {
            $deal->changeContractStart($term->startsOn);
        }
        if (null === $deal->contractEndOn()) {
            $deal->changeContractEnd($term->endsOn);
        }
        $this->em->flush();
    }
}
