<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\ChangeContactProperty;
use App\Crm\Entity\Company;
use App\Crm\Entity\Contact;
use App\Crm\Exception\ContactEmailTaken;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\Exception\StaleProperty;
use App\Crm\OwnerResolver;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\ContactRepository;
use App\Crm\ValueObject\ContactKind;
use App\Crm\ValueObject\ContactProperty;
use App\Crm\ValueObject\PropertyValue;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The contact sibling of ChangeCompanyPropertyHandler (D10, D11). An e-mail another contact has is
 * ContactEmailTaken, checked first and decided by the unique key, mapped by flushing here.
 */
final class ChangeContactPropertyHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly CompanyRepository $companies,
        private readonly OwnerResolver $owners,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ChangeContactProperty $command): ?string
    {
        $contact = $this->contacts->lock($command->contactId);
        $property = $command->property;
        $isEmail = ContactProperty::Email === $property;
        $value = PropertyValue::canonical($command->value, $isEmail);
        $current = self::current($contact, $property);

        if ($value === $current) {
            return $current;
        }
        if (PropertyValue::canonical($command->expected, $isEmail) !== $current) {
            throw StaleProperty::forField($property->value);
        }
        if (null === $value && !$property->isNullable()) {
            throw InvalidPropertyValue::required($property->value);
        }
        if ($isEmail && null !== $value && $this->contacts->emailTaken($value, $contact->id())) {
            throw ContactEmailTaken::create();
        }

        match ($property) {
            ContactProperty::FirstName => $contact->changeFirstName($value),
            ContactProperty::LastName => $contact->changeLastName((string) $value),
            ContactProperty::Email => $contact->changeEmail($value),
            ContactProperty::Phone => $contact->changePhone($value),
            ContactProperty::Position => $contact->changePosition($value),
            ContactProperty::Kind => $contact->changeKind(ContactKind::tryFrom((string) $value) ?? throw InvalidPropertyValue::unknown($property->value)),
            ContactProperty::Company => $contact->moveToCompany(null === $value ? null : $this->company($value)),
            ContactProperty::Owner => $contact->assignOwner($this->owners->resolve($value)),
        };

        if ($isEmail) {
            try {
                $this->em->flush();
            } catch (UniqueConstraintViolationException) {
                throw ContactEmailTaken::create();
            }
        }

        return self::current($contact, $property);
    }

    /**
     * The stored value as the island shows and sends it: ids for references, codes for enums.
     */
    private static function current(Contact $contact, ContactProperty $property): ?string
    {
        return match ($property) {
            ContactProperty::FirstName => $contact->firstName(),
            ContactProperty::LastName => $contact->lastName(),
            ContactProperty::Email => $contact->email(),
            ContactProperty::Phone => $contact->phone(),
            ContactProperty::Position => $contact->position(),
            ContactProperty::Kind => $contact->kind()->value,
            ContactProperty::Company => $contact->company()?->id(),
            ContactProperty::Owner => $contact->owner()?->id(),
        };
    }

    private function company(string $companyId): Company
    {
        $company = Uuid::isValid($companyId) ? $this->companies->find($companyId) : null;

        return $company ?? throw InvalidPropertyValue::unknown(ContactProperty::Company->value);
    }
}
