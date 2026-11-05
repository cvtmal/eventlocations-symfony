<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\ChangeContact;
use App\Crm\Exception\ContactEmailTaken;
use App\Crm\Exception\ContactNotFound;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\ContactRepository;
use App\Crm\ValueObject\PropertyValue;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Locks the Location, then the contact, which must sit on that Location (ContactNotFound otherwise).
 * Every field is set canonically; the e-mail check skips the contact itself, and the unique key
 * decides under concurrency.
 */
final class ChangeContactHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly CompanyRepository $companies,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ChangeContact $command): void
    {
        $company = $this->companies->lock($command->companyId);
        $contact = $this->contacts->lock($command->contactId);
        if ($contact->company()->id() !== $company->id()) {
            throw ContactNotFound::forId($command->contactId);
        }

        $lastName = PropertyValue::canonical($command->lastName) ?? throw InvalidPropertyValue::required('last_name');
        $email = PropertyValue::canonical($command->email, email: true);
        if (null !== $email && $this->contacts->emailTaken($company->id(), $email, $contact->id())) {
            throw ContactEmailTaken::create();
        }

        $contact->changeFirstName(PropertyValue::canonical($command->firstName));
        $contact->changeLastName($lastName);
        $contact->changeEmail($email);
        $contact->changePhone(PropertyValue::canonical($command->phone));
        $contact->changePosition(PropertyValue::canonical($command->position));

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw ContactEmailTaken::create();
        }
    }
}
