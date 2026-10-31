<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\CreateContact;
use App\Crm\Entity\Contact;
use App\Crm\Exception\CompanyNotFound;
use App\Crm\Exception\ContactEmailTaken;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\OwnerResolver;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\ContactRepository;
use App\Crm\ValueObject\PropertyValue;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The e-mail check runs first for a clear error; the unique key decides under concurrency, mapped
 * to ContactEmailTaken by flushing here.
 */
final class CreateContactHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly CompanyRepository $companies,
        private readonly OwnerResolver $owners,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(CreateContact $command): void
    {
        $lastName = PropertyValue::canonical($command->lastName) ?? throw InvalidPropertyValue::required('last_name');
        $email = PropertyValue::canonical($command->email, email: true);
        if (null !== $email && $this->contacts->emailTaken($email)) {
            throw ContactEmailTaken::create();
        }

        $companyId = PropertyValue::canonical($command->companyId);
        $company = null;
        if (null !== $companyId) {
            $company = Uuid::isValid($companyId) ? $this->companies->find($companyId) : null;
            if (null === $company) {
                throw CompanyNotFound::forId($companyId);
            }
        }

        $contact = new Contact(
            $command->id,
            PropertyValue::canonical($command->firstName),
            $lastName,
            $command->kind,
            $company,
            $email,
            $this->owners->resolve(PropertyValue::canonical($command->ownerId)),
        );
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
