<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\ChangeDealProperty;
use App\Crm\CompanyStageSync;
use App\Crm\Entity\Deal;
use App\Crm\Exception\ContractEndsBeforeStart;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\Exception\StaleProperty;
use App\Crm\OwnerResolver;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\DealRepository;
use App\Crm\Repository\TaskRepository;
use App\Crm\ValueObject\DealProperty;
use App\Crm\ValueObject\PropertyValue;
use App\Crm\ValueObject\RenewalWindow;
use App\Crm\ValueObject\TaskDueDate;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\ValueObject\Tier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * One inline edit under the row locks, company then deal (D11), the shape of ChangeCompanyPropertyHandler: the same
 * value is a no-op, an `expected` that differs from the stored one is StaleProperty, else the entity applies it.
 * Returns the stored value, the next `expected` of the island. A won deal's dates decide the company's stage, so its
 * edits flush and sync (N11). Its open renewal task follows `contract_end`: a new end moves the due date, no end
 * completes the task.
 */
final class ChangeDealPropertyHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly DealRepository $deals,
        private readonly TaskRepository $tasks,
        private readonly OwnerResolver $owners,
        private readonly CompanyStageSync $stageSync,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(ChangeDealProperty $command): ?string
    {
        $companyId = $this->deals->companyIdOf($command->dealId);
        $this->companies->lock($companyId);
        $deal = $this->deals->lock($command->dealId);
        $property = $command->property;
        $value = $property->canonical($command->value);
        $current = self::current($deal, $property);

        if ($value === $current) {
            return $current;
        }
        if (PropertyValue::canonical($command->expected) !== $current) {
            throw StaleProperty::forField($property->value);
        }
        if (null === $value && !$property->isNullable()) {
            throw InvalidPropertyValue::required($property->value);
        }

        $on = null === $value || !$property->isDate() ? null : TaskDueDate::parse($value);
        $start = DealProperty::ContractStart === $property ? $on : $deal->contractStartOn();
        $end = DealProperty::ContractEnd === $property ? $on : $deal->contractEndOn();
        if (null !== $start && null !== $end && $end->format('Y-m-d') < $start->format('Y-m-d')) {
            throw ContractEndsBeforeStart::forDeal($deal->id());
        }

        match ($property) {
            DealProperty::Name => $deal->rename((string) $value),
            DealProperty::Venue => $deal->changeVenue(null === $value ? null : $this->companies->venueOf($companyId, $value)),
            DealProperty::Tier => $deal->changeTier(null === $value ? null : Tier::tryFrom($value) ?? throw InvalidPropertyValue::unknown($property->value)),
            DealProperty::Amount => $deal->changeAmount(null === $value ? null : (int) $value),
            DealProperty::ContractStart => $deal->changeContractStart($on),
            DealProperty::ContractEnd => $deal->changeContractEnd($on),
            DealProperty::ExpectedClose => $deal->changeExpectedClose($on),
            DealProperty::Owner => $deal->assignOwner($this->owners->resolve($value)),
        };

        if ($deal->stage()->isWon()) {
            if (DealProperty::ContractEnd === $property) {
                $this->followContractEnd($deal, $end);
            }
            $this->em->flush();
            $this->stageSync->syncCompany($companyId);
        }

        return self::current($deal, $property);
    }

    private function followContractEnd(Deal $deal, ?\DateTimeImmutable $end): void
    {
        $task = $this->tasks->renewalOf($deal->id());
        if (null === $task || $task->isDone()) {
            return;
        }

        $now = $this->clock->now();
        if (null === $end) {
            $task->complete($now);
        } else {
            $task->moveDueTo(RenewalWindow::taskDueOn($end, $now), $now);
        }
    }

    /**
     * The stored value as the island shows and sends it: ids for references, codes for enums, Rappen, `Y-m-d`.
     */
    private static function current(Deal $deal, DealProperty $property): ?string
    {
        $amount = $deal->amountRappen();

        return match ($property) {
            DealProperty::Name => $deal->name(),
            DealProperty::Venue => $deal->venue()?->id(),
            DealProperty::Tier => $deal->tier()?->value,
            DealProperty::Amount => null === $amount ? null : (string) $amount,
            DealProperty::ContractStart => $deal->contractStartOn()?->format('Y-m-d'),
            DealProperty::ContractEnd => $deal->contractEndOn()?->format('Y-m-d'),
            DealProperty::ExpectedClose => $deal->expectedCloseOn()?->format('Y-m-d'),
            DealProperty::Owner => $deal->owner()?->id(),
        };
    }
}
