<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\CreateDeal;
use App\Crm\Entity\Deal;
use App\Crm\Entity\DealContact;
use App\Crm\Entity\DealStage;
use App\Crm\Exception\ContactOfAnotherCompany;
use App\Crm\Exception\ContractEndsBeforeStart;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\OwnerResolver;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\CompanyVenueRepository;
use App\Crm\Repository\ContactRepository;
use App\Crm\Repository\DealRepository;
use App\Crm\Repository\DealStageRepository;
use App\Crm\ValueObject\PropertyValue;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The form offers only the company's venues and contacts; the handler holds the rule under the company's lock.
 */
final class CreateDealHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly CompanyVenueRepository $companyVenues,
        private readonly ContactRepository $contacts,
        private readonly DealRepository $deals,
        private readonly DealStageRepository $stages,
        private readonly OwnerResolver $owners,
        private readonly EntityManagerInterface $em,
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
            null === $command->venueId ? null : $this->companyVenues->venueOf($company->id(), $command->venueId),
            $command->tier,
            $command->amountRappen,
            $command->contractStartOn,
            $command->contractEndOn,
            $command->expectedCloseOn,
            $this->owners->resolve($command->ownerId),
        );
        $this->deals->add($deal);

        foreach (array_unique($command->contactIds) as $contactId) {
            $contact = $this->contacts->lock($contactId);
            if ($contact->company()?->id() !== $company->id()) {
                throw ContactOfAnotherCompany::forContact($contactId);
            }
            $this->em->persist(new DealContact($deal, $contact));
        }
    }
}
