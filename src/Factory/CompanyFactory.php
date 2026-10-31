<?php

declare(strict_types=1);

namespace App\Factory;

use App\Crm\Entity\Company;
use App\Crm\Entity\CompanyVenue;
use App\Crm\ValueObject\CompanyOrigin;
use App\Crm\ValueObject\CompanyStage;
use App\Shared\Uid\EntityId;
use App\Venues\Entity\Venue;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function Zenstruck\Foundry\Persistence\persist;

/**
 * A manually created prospect without details or owner. States add the record's properties and
 * venue links, so tests describe only what they need.
 *
 * @extends PersistentObjectFactory<Company>
 */
final class CompanyFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Company::class;
    }

    public function inStage(CompanyStage $stage): self
    {
        return $this->afterInstantiate(static fn (Company $company) => $company->changeStage($stage));
    }

    public function withDetails(): self
    {
        return $this->afterInstantiate(static function (Company $company): void {
            $company->changeLegalName($company->name().' AG');
            $company->changeWebsite('https://www.'.self::faker()->unique()->domainWord().'.ch');
            $company->changePhone(self::faker()->numerify('+41 44 ### ## ##'));
            $company->changeEmail(self::faker()->unique()->safeEmail());
            $company->changeAddress(self::faker()->streetAddress().', '.self::faker()->postcode().' '.self::faker()->city());
        });
    }

    /**
     * Links the venues after the company is stored (crm_company_venues).
     */
    public function withVenues(Venue ...$venues): self
    {
        return $this->afterPersist(static function (Company $company) use ($venues): void {
            foreach ($venues as $venue) {
                persist(CompanyVenue::class, ['company' => $company, 'venue' => $venue]);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'id' => EntityId::generate(),
            'name' => self::faker()->unique()->company(),
            'origin' => CompanyOrigin::Manual,
            'owner' => null,
        ];
    }
}
