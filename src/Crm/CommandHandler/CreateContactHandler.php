<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\CreateContact;
use App\Crm\Entity\Contact;
use App\Crm\Exception\ContactEmailTaken;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\ContactRepository;
use App\Crm\ValueObject\PropertyValue;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Locks the Location first (lock order company → contact). The e-mail check runs per Location for a
 * clear error; uk_crm_contacts_company_email decides under concurrency, mapped to ContactEmailTaken
 * by flushing here.
 */
final class CreateContactHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly CompanyRepository $companies,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(CreateContact $command): void
    {
        $company = $this->companies->lock($command->companyId);
        $lastName = PropertyValue::canonical($command->lastName) ?? throw InvalidPropertyValue::required('last_name');
        $email = PropertyValue::canonical($command->email, email: true);
        if (null !== $email && $this->contacts->emailTaken($company->id(), $email)) {
            throw ContactEmailTaken::create();
        }

        $contact = new Contact($command->id, $company, PropertyValue::canonical($command->firstName), $lastName, $email);
        $contact->changePhone(PropertyValue::canonical($command->phone));
        $contact->changePosition(PropertyValue::canonical($command->position));
        $this->em->persist($contact);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw ContactEmailTaken::create();
        }
    }
}
