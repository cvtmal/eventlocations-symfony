<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\RemoveContact;
use App\Crm\Exception\ContactNotFound;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\ContactRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes the contact row only (Q10): nothing else references a contact any more (#55).
 */
final class RemoveContactHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly CompanyRepository $companies,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(RemoveContact $command): void
    {
        $company = $this->companies->lock($command->companyId);
        $contact = $this->contacts->lock($command->contactId);
        if ($contact->company()->id() !== $company->id()) {
            throw ContactNotFound::forId($command->contactId);
        }

        $this->em->remove($contact);
    }
}
