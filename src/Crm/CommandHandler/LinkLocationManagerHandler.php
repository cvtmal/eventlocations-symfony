<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\LinkLocationManager;
use App\Crm\Entity\Activity;
use App\Crm\Entity\ActivityCompany;
use App\Crm\Entity\ActivityContact;
use App\Crm\Entity\ActivityType;
use App\Crm\Entity\Company;
use App\Crm\Entity\Contact;
use App\Crm\Repository\CompanyVenueRepository;
use App\Crm\Repository\ContactRepository;
use App\Crm\ValueObject\ContactEmail;
use App\Crm\ValueObject\ContactKind;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Uid\EntityId;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Writes `Contact`, `Activity` and the links itself, as `IngestInquiryHandler` does. A known e-mail is the same person:
 * staff's values stay, only a blank first name, phone or company is filled, and a contact on another company stays
 * there, because one person has one company. The kind stays too, with one exception: a `couple` becomes a
 * `venue_contact`, because the inquiry ingest set that kind, not staff, and `app:crm:purge` would erase a manager who
 * kept it. The entry still sits on the venue's company. Nobody wrote it, so it has no actor. A manager removed and
 * approved again gets a second entry, which is what happened.
 */
final class LinkLocationManagerHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly CompanyVenueRepository $companyVenues,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly EnabledLocale $locales,
    ) {
    }

    public function __invoke(LinkLocationManager $command): void
    {
        $company = $this->companyVenues->forVenue($command->venueId)?->company();
        $contact = $this->contactOf($command, $company);
        $activity = new Activity(
            EntityId::generate(),
            $this->em->getReference(ActivityType::class, ActivityType::NOTE) ?? throw new \LogicException('ActivityType has no subclass, so a reference always resolves.'),
            $this->clock->now(),
            null,
            null,
            $this->translator->trans($command->activityKey, ['venue' => $command->venueName], 'crm', $this->locales->default()),
        );

        $this->em->persist($activity);
        $this->em->persist(new ActivityContact($activity, $contact));
        if (null !== $company) {
            $this->em->persist(new ActivityCompany($activity, $company));
        }
    }

    private function contactOf(LinkLocationManager $command, ?Company $company): Contact
    {
        $email = ContactEmail::normalise($command->email);
        $contact = $this->contacts->findOneBy(['email' => $email]);
        if (null === $contact) {
            $contact = new Contact(EntityId::generate(), $command->firstName, $command->lastName, ContactKind::VenueContact, $company, $email);
            $contact->changePhone($command->phone);
            $this->em->persist($contact);

            return $contact;
        }

        if (null === $contact->firstName()) {
            $contact->changeFirstName($command->firstName);
        }
        if (null === $contact->phone() && null !== $command->phone) {
            $contact->changePhone($command->phone);
        }
        if (null === $contact->company() && null !== $company) {
            $contact->moveToCompany($company);
        }
        if (ContactKind::Couple === $contact->kind()) {
            $contact->changeKind(ContactKind::VenueContact);
        }

        return $contact;
    }
}
