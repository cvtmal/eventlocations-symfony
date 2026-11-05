<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\CreateCompanyForVenue;
use App\Crm\Entity\Company;
use App\Crm\Entity\Contact;
use App\Crm\Exception\VenueNotFound;
use App\Crm\Repository\CompanyRepository;
use App\Crm\ValueObject\BootstrapOutcome;
use App\Crm\ValueObject\CompanyOrigin;
use App\Crm\ValueObject\ContactEmail;
use App\Crm\ValueObject\PropertyValue;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Uid\EntityId;
use App\Venues\Entity\Venue;
use App\Venues\ReadModel\VenueContactFacts;
use App\Venues\ReadModel\VenueContactFactsQuery;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Takes the venue's advisory lock (shared with LinkVenue) before it looks for the venue's Location, so a
 * re-run, a second tab or a staff assignment cannot give a venue two Locations. Only reads the venue, so
 * `cms_edited_at` stays. The contact is the first row of a brand-new Location, so its address can
 * never be taken (the unique key is per Location, #55).
 */
final class CreateCompanyForVenueHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueContactFactsQuery $venues,
        private readonly CompanyRepository $companies,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(CreateCompanyForVenue $command): BootstrapOutcome
    {
        $venue = $this->venues->find($command->venueId) ?? throw VenueNotFound::forId($command->venueId);

        $this->companies->lockVenue($venue->id);
        if (null !== $this->companies->forVenue($venue->id)) {
            return BootstrapOutcome::skipped();
        }

        $company = new Company($command->companyId, $venue->name, CompanyOrigin::Venue);
        $company->changeWebsite(PropertyValue::canonical($venue->website));
        $company->changePhone(PropertyValue::canonical($venue->phone));
        $company->changeEmail(ContactEmail::normalise($venue->inquiryEmail));
        $company->changeAddress(self::address($venue));
        $company->assignVenue($this->em->getReference(Venue::class, $venue->id) ?? throw VenueNotFound::forId($venue->id));
        $this->em->persist($company);

        $person = PropertyValue::canonical($venue->contactPerson);
        if (null === $person) {
            return BootstrapOutcome::created($company->id(), false);
        }

        [$firstName, $lastName] = self::split($person);
        $this->em->persist(new Contact(EntityId::generate(), $company, $firstName, $lastName, ContactEmail::normalise($venue->inquiryEmail)));

        return BootstrapOutcome::created($company->id(), true);
    }

    private static function address(VenueContactFacts $venue): ?string
    {
        $place = trim($venue->zip.' '.$venue->townName);

        return PropertyValue::canonical(implode(', ', array_filter([$venue->street, $place])));
    }

    /**
     * The last word is the last name, the rest the first name (D19), so "Laura Meier" and
     * "Anna Maria von Allmen" read as staff expect, and "Meier" alone is a last name.
     *
     * @return array{?string, string}
     */
    private static function split(string $person): array
    {
        $words = preg_split('/\s+/u', $person, flags: \PREG_SPLIT_NO_EMPTY) ?: [$person];
        $lastName = array_pop($words);

        return [[] === $words ? null : implode(' ', $words), $lastName];
    }
}
