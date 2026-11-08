<?php

declare(strict_types=1);

namespace App\Factory;

use App\Crm\Entity\Company;
use App\Crm\ValueObject\CompanyOrigin;
use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\Outreach;
use App\Shared\Uid\EntityId;
use App\Venues\Entity\Venue;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A manually created prospect without details or owner. States add the record's properties and
 * the Eintrag, so tests describe only what they need.
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
     * Gives the Location its Eintrag (`crm_companies.venue_id`, #56).
     */
    public function withVenue(Venue $venue): self
    {
        return $this->afterInstantiate(static fn (Company $company) => $company->assignVenue($venue));
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * G11: the Location's outreach fields.
     */
    public function withOutreach(Outreach $outreach): self
    {
        return $this->afterInstantiate(static function (Company $company) use ($outreach): void {
            $company->changeOutreach($outreach);
        });
    }

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
