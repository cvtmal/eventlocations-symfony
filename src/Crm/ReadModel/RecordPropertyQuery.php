<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\CompanyProperty;
use App\Crm\ValueObject\ContactProperty;
use App\Crm\ValueObject\PropertyValue;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The current value of one field of a company or contact, for the API's no-op check before it
 * dispatches (P1): an unchanged save leaves no audit row. One statement; null when the record does
 * not exist, a non-UUID id included, so no 22P02 reaches a 500. The handler repeats the check
 * under its row lock.
 */
final class RecordPropertyQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function company(string $companyId, CompanyProperty $property): ?CurrentProperty
    {
        $column = match ($property) {
            CompanyProperty::Name => 'name',
            CompanyProperty::LegalName => 'legal_name',
            CompanyProperty::Website => 'website',
            CompanyProperty::Phone => 'phone',
            CompanyProperty::Email => 'email',
            CompanyProperty::Address => 'address',
            CompanyProperty::Stage => 'stage',
            CompanyProperty::Owner => 'owner_id',
            CompanyProperty::Notes => 'notes',
        };

        return $this->read('crm_companies', $column, $companyId, CompanyProperty::Email === $property);
    }

    public function contact(string $contactId, ContactProperty $property): ?CurrentProperty
    {
        $column = match ($property) {
            ContactProperty::FirstName => 'first_name',
            ContactProperty::LastName => 'last_name',
            ContactProperty::Email => 'email',
            ContactProperty::Phone => 'phone',
            ContactProperty::Position => 'position',
            ContactProperty::Kind => 'kind',
            ContactProperty::Company => 'company_id',
            ContactProperty::Owner => 'owner_id',
        };

        return $this->read('crm_contacts', $column, $contactId, ContactProperty::Email === $property);
    }

    /**
     * Table and column come from the matches above, never from the request.
     */
    private function read(string $table, string $column, string $id, bool $email): ?CurrentProperty
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            \sprintf('SELECT %s::text AS value FROM %s WHERE id = :id', $column, $table),
            ['id' => $id],
        );
        if (false === $row) {
            return null;
        }

        $value = $row['value'];
        \assert(null === $value || \is_string($value));

        return new CurrentProperty(PropertyValue::canonical($value, $email));
    }
}
