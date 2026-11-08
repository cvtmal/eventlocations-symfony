<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\CompanyProperty;
use App\Crm\ValueObject\DealProperty;
use App\Crm\ValueObject\PropertyValue;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The current value of one field of a company or deal, for the API's no-op check before it
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

    /**
     * The stored form, as `DealProperty::canonical()` writes it: Rappen, `Y-m-d`, ids, codes (a tier's is its `tiers.code`).
     */
    public function deal(string $dealId, DealProperty $property): ?CurrentProperty
    {
        $column = match ($property) {
            DealProperty::Name => 'name',
            DealProperty::Venue => 'venue_id',
            DealProperty::Tier => '(SELECT t.code FROM tiers t WHERE t.id = crm_deals.tier_id)',
            DealProperty::Amount => 'amount_rappen',
            DealProperty::ContractStart => 'contract_start_on',
            DealProperty::ContractEnd => 'contract_end_on',
            DealProperty::ExpectedClose => 'expected_close_on',
            DealProperty::Owner => 'owner_id',
        };

        return $this->read('crm_deals', $column, $dealId, false);
    }

    /**
     * Table and column (or the one expression) come from the matches above, never from the request.
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
