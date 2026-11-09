<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\ImportLocation;
use App\Crm\Entity\Company;
use App\Crm\Entity\Contact;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\Exception\LocationSuppressed;
use App\Crm\Exception\VenueAlreadyLinked;
use App\Crm\Exception\VenueNotFound;
use App\Crm\ReadModel\SuppressionQuery;
use App\Crm\Repository\CompanyRepository;
use App\Crm\ValueObject\CompanyOrigin;
use App\Crm\ValueObject\ContactEmail;
use App\Crm\ValueObject\Outreach;
use App\Shared\Funnel\CampaignCode;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Entity\Venue;
use App\Venues\ReadModel\VenueKeyQuery;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes one Location of the contact list (F14). Refuses the whole row when one of its addresses is on the
 * suppression list (checked again here, inside the transaction). The venue is linked by LinkVenue's rules: under the
 * venue's advisory lock, refused when another Location has it (`VenueAlreadyLinked`, also from the unique key).
 */
final class ImportLocationHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly SuppressionQuery $suppressions,
        private readonly CompanyRepository $companies,
        private readonly VenueKeyQuery $venues,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ImportLocation $command): void
    {
        foreach ([$command->email, $command->contactEmail] as $email) {
            if (null !== $email && $this->suppressions->isAddressSuppressed($email)) {
                throw LocationSuppressed::forImport();
            }
        }
        $code = $command->outreach->campaignCode;
        if (null !== $code && !CampaignCode::isValid($code)) {
            throw InvalidPropertyValue::unknown('campaign_code');
        }

        $company = new Company($command->companyId, $command->name, CompanyOrigin::Manual);
        $company->changeAddress($command->address);
        $company->changeEmail(ContactEmail::normalise($command->email));
        $company->changePhone($command->phone);
        $company->changeWebsite($command->website);
        if (!$command->outreach->equals(new Outreach())) {
            $company->changeOutreach($command->outreach);
        }
        $this->em->persist($company);

        if (null !== $command->contactId) {
            if (null === $command->lastName) {
                throw InvalidPropertyValue::required('last_name');
            }
            $contact = new Contact($command->contactId, $company, $command->firstName, $command->lastName, ContactEmail::normalise($command->contactEmail));
            $contact->changePhone($command->contactPhone);
            $contact->changePosition($command->position);
            $this->em->persist($contact);
        }

        if (null !== $command->venueNumber) {
            $this->linkVenue($company, $command->venueNumber);
        }
    }

    private function linkVenue(Company $company, int $number): void
    {
        $venue = $this->venues->find($number) ?? throw VenueNotFound::forId((string) $number);
        $this->companies->lockVenue($venue->id);
        if (null !== $this->companies->forVenue($venue->id)) {
            throw VenueAlreadyLinked::forVenue($venue->id);
        }
        $company->assignVenue($this->em->getReference(Venue::class, $venue->id) ?? throw VenueNotFound::forId($venue->id));

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw VenueAlreadyLinked::forVenue($venue->id);
        }
    }
}
