<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\RemoveContact;
use App\Crm\Command\SuppressCompany;
use App\Crm\Command\WithdrawConsent;
use App\Crm\Entity\Activity;
use App\Crm\Entity\ActivityCompany;
use App\Crm\Entity\ActivityType;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\Exception\NoConsentRecorded;
use App\Crm\Exception\VenueNotFound;
use App\Crm\Exception\VenueNotLinked;
use App\Crm\ReadModel\ConsentQuery;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\ContactRepository;
use App\Crm\Repository\SuppressionRepository;
use App\Crm\ValueObject\SuppressionReason;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Uid\EntityId;
use App\Venues\Command\DeleteVenue;
use App\Venues\Command\UnpublishVenue;
use App\Venues\ReadModel\VenueKeyQuery;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Takes a consent entry down in one transaction, under the Location's lock (F14, D29):
 *
 * 1. Refuses unless the venue belongs to the Location and the Location holds a consent entry (`NoConsentRecorded`).
 * 2. Suppresses the Location with the reason objection (Open Decision 4), first, so the addresses it holds now go on
 *    the list too before they are cleared, and each address a yes came from (`consent.from`), which it may not hold.
 * 3. Takes the venue offline and deletes it; DeleteVenue leaves a tombstone of the content key, so the import never
 *    brings it back.
 * 4. Removes the Location's contacts and clears the website, phone, e-mail and address copied from the entry.
 * 5. Writes a note with no actor: the withdrawal, dated the day it came. The consent note stays beside it.
 *
 * The Location, its name and its timeline stay, as the proof of the yes and of its withdrawal.
 */
final class WithdrawConsentHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly ContactRepository $contacts,
        private readonly ConsentQuery $consents,
        private readonly SuppressionRepository $suppressions,
        private readonly VenueKeyQuery $venues,
        private readonly MessageBusInterface $commandBus,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly EnabledLocale $locales,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(WithdrawConsent $command): void
    {
        $zone = new \DateTimeZone('Europe/Zurich');
        $on = \DateTimeImmutable::createFromFormat('!Y-m-d', $command->withdrawnOn, $zone);
        if (false === $on || $on->format('Y-m-d') !== $command->withdrawnOn) {
            throw InvalidPropertyValue::unknown('withdrawn_on');
        }

        $company = $this->companies->lock($command->companyId);
        $venue = $this->venues->find($command->venueNumber);
        if (null === $venue) {
            throw VenueNotFound::forId((string) $command->venueNumber);
        }
        if ($company->venue()?->id() !== $venue->id) {
            throw VenueNotLinked::toCompany($venue->id, $company->id());
        }
        if (!$this->consents->has($company->id())) {
            throw NoConsentRecorded::forVenue($command->venueNumber);
        }

        $this->commandBus->dispatch(new SuppressCompany($company->id(), SuppressionReason::Objection));
        // The address a yes came from may be none the Location holds; without its own row, a contact list naming it
        // would bring the Location back past G11.
        $now = $this->clock->now();
        foreach ($this->consents->fromAddresses($company->id()) as $email) {
            $this->suppressions->addEmail($email, SuppressionReason::Objection, $now);
        }

        if ($venue->isPublished) {
            $this->commandBus->dispatch(new UnpublishVenue($venue->number));
        }
        $company->releaseVenue();
        $this->commandBus->dispatch(new DeleteVenue($venue->number, $venue->name, $venue->contentKey));

        foreach ($this->contacts->findBy(['company' => $company]) as $contact) {
            $this->commandBus->dispatch(new RemoveContact($contact->id(), $company->id()));
        }
        $company->changeWebsite(null);
        $company->changePhone(null);
        $company->changeEmail(null);
        $company->changeAddress(null);

        $now = $now->setTimezone($zone);
        $activity = new Activity(
            EntityId::generate(),
            $this->em->getReference(ActivityType::class, ActivityType::NOTE) ?? throw new \LogicException('ActivityType has no subclass, so a reference always resolves.'),
            $now->format('Y-m-d') === $command->withdrawnOn ? $now : $on->setTime(12, 0),
            null,
            null,
            $this->translator->trans('timeline.system.consent_withdrawn', ['date' => $on->format('d.m.Y')], 'crm', $this->locales->default()),
            ['consent_withdrawn' => ['on' => $command->withdrawnOn]],
        );
        $this->em->persist($activity);
        $this->em->persist(new ActivityCompany($activity, $company));
    }
}
