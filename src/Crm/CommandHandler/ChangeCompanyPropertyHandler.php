<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\ChangeCompanyProperty;
use App\Crm\Entity\Company;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\Exception\StaleProperty;
use App\Crm\OwnerResolver;
use App\Crm\Repository\CompanyRepository;
use App\Crm\ValueObject\CompanyProperty;
use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\PropertyValue;
use App\Shared\Messenger\CommandHandlerInterface;

/**
 * One inline edit (D10, D11), under the row lock: the new value equal to the stored one is a
 * no-op, an `expected` that differs from the stored one is StaleProperty, else the entity applies
 * it. Returns the canonical stored value, the next `expected` of the island. The API compares
 * first (P1), so a no-op normally never reaches this handler and leaves no audit row.
 */
final class ChangeCompanyPropertyHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly OwnerResolver $owners,
    ) {
    }

    public function __invoke(ChangeCompanyProperty $command): ?string
    {
        $company = $this->companies->lock($command->companyId);
        $property = $command->property;
        $isEmail = CompanyProperty::Email === $property;
        $value = PropertyValue::canonical($command->value, $isEmail);
        $current = self::current($company, $property);

        if ($value === $current) {
            return $current;
        }
        if (PropertyValue::canonical($command->expected, $isEmail) !== $current) {
            throw StaleProperty::forField($property->value);
        }
        if (null === $value && !$property->isNullable()) {
            throw InvalidPropertyValue::required($property->value);
        }

        match ($property) {
            CompanyProperty::Name => $company->rename((string) $value),
            CompanyProperty::LegalName => $company->changeLegalName($value),
            CompanyProperty::Website => $company->changeWebsite($value),
            CompanyProperty::Phone => $company->changePhone($value),
            CompanyProperty::Email => $company->changeEmail($value),
            CompanyProperty::Address => $company->changeAddress($value),
            CompanyProperty::Stage => $company->changeStage(CompanyStage::tryFrom((string) $value) ?? throw InvalidPropertyValue::unknown($property->value)),
            CompanyProperty::Owner => $company->assignOwner($this->owners->resolve($value)),
            CompanyProperty::Notes => $company->changeNotes($value),
        };

        return self::current($company, $property);
    }

    /**
     * The stored value as the island shows and sends it: ids for references, codes for enums.
     */
    private static function current(Company $company, CompanyProperty $property): ?string
    {
        return match ($property) {
            CompanyProperty::Name => $company->name(),
            CompanyProperty::LegalName => $company->legalName(),
            CompanyProperty::Website => $company->website(),
            CompanyProperty::Phone => $company->phone(),
            CompanyProperty::Email => $company->email(),
            CompanyProperty::Address => $company->address(),
            CompanyProperty::Stage => $company->stage()->value,
            CompanyProperty::Owner => $company->owner()?->id(),
            CompanyProperty::Notes => $company->notes(),
        };
    }
}
