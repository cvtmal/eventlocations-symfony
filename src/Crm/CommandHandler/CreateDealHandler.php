<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\CreateDeal;
use App\Crm\Entity\Deal;
use App\Crm\Entity\DealStage;
use App\Crm\Exception\ContractEndsBeforeStart;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\OwnerResolver;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\DealRepository;
use App\Crm\Repository\DealStageRepository;
use App\Crm\TierResolver;
use App\Crm\ValueObject\PropertyValue;
use App\Shared\Messenger\CommandHandlerInterface;

/**
 * The form offers only the company's venues; the handler holds the rule under the company's lock. The tier comes as a
 * code, which the form offers from `tiers`; an unknown one is the handler's `InvalidPropertyValue`, like an owner's.
 */
final class CreateDealHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly DealRepository $deals,
        private readonly DealStageRepository $stages,
        private readonly OwnerResolver $owners,
        private readonly TierResolver $tiers,
    ) {
    }

    public function __invoke(CreateDeal $command): void
    {
        $name = PropertyValue::canonical($command->name) ?? throw InvalidPropertyValue::required('name');
        $company = $this->companies->lock($command->companyId);
        if (null !== $command->contractStartOn && null !== $command->contractEndOn && $command->contractEndOn < $command->contractStartOn) {
            throw ContractEndsBeforeStart::forDeal($command->id);
        }

        $deal = new Deal(
            $command->id,
            $name,
            $company,
            $this->stages->get(DealStage::NEW),
            null === $command->venueId ? null : $this->companies->venueOf($company->id(), $command->venueId),
            $this->tiers->resolve($command->tierCode),
            $command->amountRappen,
            $command->contractStartOn,
            $command->contractEndOn,
            $command->expectedCloseOn,
            $this->owners->resolve($command->ownerId),
        );
        $this->deals->add($deal);
    }
}
