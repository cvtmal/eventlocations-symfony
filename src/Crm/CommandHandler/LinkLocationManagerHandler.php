<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\LinkLocationManager;
use App\Crm\Entity\Activity;
use App\Crm\Entity\ActivityCompany;
use App\Crm\Entity\ActivityType;
use App\Crm\Entity\Company;
use App\Crm\Entity\Contact;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\ContactRepository;
use App\Crm\ValueObject\ContactEmail;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Uid\EntityId;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Writes `Contact`, `Activity` and the link itself, as `IngestInquiryHandler` does. A manager is one contact row per
 * Location (#55): the row is looked up by (Location, e-mail) and created on the venue's Location with the position
 * "Location-Manager". A row that is already there keeps staff's values; only a blank first name, phone or position is
 * filled. The values are copied once: a later `/konto` profile change does not update the row (Q8). A venue without
 * a Location gets nothing here; the listener creates the Location first, and logs when that fails. The entry sits on
 * the Location. Nobody wrote it, so it has no actor. A manager removed and approved again gets a second entry, which
 * is what happened.
 */
final class LinkLocationManagerHandler implements CommandHandlerInterface
{
    /** The position of a manager's contact row; the #55 migration writes the same value. */
    public const string MANAGER_POSITION = 'Location-Manager';

    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly CompanyRepository $companies,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly EnabledLocale $locales,
    ) {
    }

    public function __invoke(LinkLocationManager $command): void
    {
        $company = $this->companies->forVenue($command->venueId);
        if (null === $company) {
            return;
        }

        $this->writeContact($command, $company);
        $activity = new Activity(
            EntityId::generate(),
            $this->em->getReference(ActivityType::class, ActivityType::NOTE) ?? throw new \LogicException('ActivityType has no subclass, so a reference always resolves.'),
            $this->clock->now(),
            null,
            null,
            $this->translator->trans($command->activityKey, ['venue' => $command->venueName], 'crm', $this->locales->default()),
        );

        $this->em->persist($activity);
        $this->em->persist(new ActivityCompany($activity, $company));
    }

    private function writeContact(LinkLocationManager $command, Company $company): void
    {
        $email = ContactEmail::normalise($command->email);
        $contact = $this->contacts->findOneBy(['company' => $company, 'email' => $email]);
        if (null === $contact) {
            $contact = new Contact(EntityId::generate(), $company, $command->firstName, $command->lastName, $email);
            $contact->changePhone($command->phone);
            $contact->changePosition(self::MANAGER_POSITION);
            $this->em->persist($contact);

            return;
        }

        if (null === $contact->firstName()) {
            $contact->changeFirstName($command->firstName);
        }
        if (null === $contact->phone() && null !== $command->phone) {
            $contact->changePhone($command->phone);
        }
        if (null === $contact->position()) {
            $contact->changePosition(self::MANAGER_POSITION);
        }
    }
}
