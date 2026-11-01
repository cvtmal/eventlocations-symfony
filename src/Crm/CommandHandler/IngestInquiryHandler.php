<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\IngestInquiry;
use App\Crm\Entity\Activity;
use App\Crm\Entity\ActivityCompany;
use App\Crm\Entity\ActivityContact;
use App\Crm\Entity\ActivityType;
use App\Crm\Entity\Contact;
use App\Crm\Entity\InquiryLink;
use App\Crm\Repository\CompanyVenueRepository;
use App\Crm\Repository\ContactRepository;
use App\Crm\Repository\InquiryLinkRepository;
use App\Crm\ValueObject\ContactEmail;
use App\Crm\ValueObject\ContactKind;
use App\Inquiries\Entity\Inquiry;
use App\Inquiries\Repository\InquiryRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Uid\EntityId;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes `Contact`, `Activity` and the links itself: `CreateContact` has no upsert and `LogActivity` refuses a
 * non-composable type. A link row means "already ingested", so a repeat writes nothing, and an inquiry the purge took
 * is skipped. A known e-mail is the same person: staff's values stay, only a blank first name or phone is filled. The
 * entry's title is the venue's name, so no personal data is copied; the timeline reads the couple's name from
 * `inquiries`, live. The answer says whether anything was written.
 */
final class IngestInquiryHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly InquiryRepository $inquiries,
        private readonly InquiryLinkRepository $links,
        private readonly ContactRepository $contacts,
        private readonly CompanyVenueRepository $companyVenues,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(IngestInquiry $command): bool
    {
        $inquiry = $this->inquiries->find($command->inquiryId);
        if (null === $inquiry || $this->links->has($command->inquiryId)) {
            return false;
        }

        $contact = $this->contactOf($inquiry);
        $activity = new Activity(
            EntityId::generate(),
            $this->em->getReference(ActivityType::class, ActivityType::INQUIRY) ?? throw new \LogicException('ActivityType has no subclass, so a reference always resolves.'),
            $inquiry->createdAt(),
            null,
            $inquiry->venueName(),
            null,
        );

        $this->em->persist($activity);
        $this->em->persist(new ActivityContact($activity, $contact));
        $venueId = $inquiry->venue()?->id();
        $companyVenue = null === $venueId ? null : $this->companyVenues->forVenue($venueId);
        if (null !== $companyVenue) {
            $this->em->persist(new ActivityCompany($activity, $companyVenue->company()));
        }
        $this->em->persist(new InquiryLink($inquiry, $contact, $activity));

        return true;
    }

    private function contactOf(Inquiry $inquiry): Contact
    {
        $email = ContactEmail::normalise($inquiry->email());
        $contact = $this->contacts->findOneBy(['email' => $email]);
        if (null === $contact) {
            $contact = new Contact(EntityId::generate(), $inquiry->firstName(), $inquiry->lastName(), ContactKind::Couple, email: $email);
            $contact->changePhone($inquiry->phone());
            $this->em->persist($contact);

            return $contact;
        }

        if (null === $contact->firstName()) {
            $contact->changeFirstName($inquiry->firstName());
        }
        if (null === $contact->phone() && null !== $inquiry->phone()) {
            $contact->changePhone($inquiry->phone());
        }

        return $contact;
    }
}
