<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\IngestInquiry;
use App\Crm\Entity\Activity;
use App\Crm\Entity\ActivityCompany;
use App\Crm\Entity\ActivityType;
use App\Crm\Entity\InquiryLink;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\InquiryLinkRepository;
use App\Inquiries\Repository\InquiryRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Uid\EntityId;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes the `Activity` and the links itself: `LogActivity` refuses a non-composable type. A link row means "already
 * ingested", so a repeat writes nothing, and an inquiry the purge took is skipped. The couple is no CRM contact (#55):
 * it lives only as the Anfrage, and the timeline and inbox read its name from `inquiries`, live. When the venue has a
 * Location, the entry goes on its timeline with the venue's name as title, so no personal data is copied. A venue
 * without a Location (or a gone venue) gets the link row only, no entry and no automatic Location (D5). The answer
 * says whether anything was written.
 */
final class IngestInquiryHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly InquiryRepository $inquiries,
        private readonly InquiryLinkRepository $links,
        private readonly CompanyRepository $companies,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(IngestInquiry $command): bool
    {
        $inquiry = $this->inquiries->find($command->inquiryId);
        if (null === $inquiry || $this->links->has($command->inquiryId)) {
            return false;
        }

        $venueId = $inquiry->venue()?->id();
        $company = null === $venueId ? null : $this->companies->forVenue($venueId);
        if (null === $company) {
            $this->em->persist(new InquiryLink($inquiry, null));

            return true;
        }

        $activity = new Activity(
            EntityId::generate(),
            $this->em->getReference(ActivityType::class, ActivityType::INQUIRY) ?? throw new \LogicException('ActivityType has no subclass, so a reference always resolves.'),
            $inquiry->createdAt(),
            null,
            $inquiry->venueName(),
            null,
        );
        $this->em->persist($activity);
        $this->em->persist(new ActivityCompany($activity, $company));
        $this->em->persist(new InquiryLink($inquiry, $activity));

        return true;
    }
}
