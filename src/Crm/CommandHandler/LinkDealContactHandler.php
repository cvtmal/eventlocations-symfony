<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\LinkDealContact;
use App\Crm\Entity\DealContact;
use App\Crm\Exception\ContactOfAnotherCompany;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\ContactRepository;
use App\Crm\Repository\DealRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;

final class LinkDealContactHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly ContactRepository $contacts,
        private readonly DealRepository $deals,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(LinkDealContact $command): void
    {
        $companyId = $this->deals->companyIdOf($command->dealId);
        $this->companies->lock($companyId);
        $deal = $this->deals->lock($command->dealId);
        $contact = $this->contacts->lock($command->contactId);

        if ($contact->company()?->id() !== $companyId) {
            throw ContactOfAnotherCompany::forContact($command->contactId);
        }
        if (!$this->deals->hasContact($deal->id(), $contact->id())) {
            $this->em->persist(new DealContact($deal, $contact));
        }
    }
}
