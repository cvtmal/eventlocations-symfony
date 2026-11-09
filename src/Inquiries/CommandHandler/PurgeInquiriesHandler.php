<?php

declare(strict_types=1);

namespace App\Inquiries\CommandHandler;

use App\Inquiries\Command\PurgeInquiries;
use App\Inquiries\Repository\InquiryBriefRepository;
use App\Inquiries\Repository\InquiryRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * LEG-2. What #29 derives goes with the row: `crm_inquiry_links` cascades, and the `inquiry` entry
 * holds no personal data (its title is the venue's name; the couple is no CRM contact, #55), so no
 * purge event exists.
 *
 * A brief's parent (F13) goes with the same cutoff, after its children: it holds no personal data, and its children
 * share its creation time. The count stays the number of inquiries.
 */
final class PurgeInquiriesHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly InquiryRepository $inquiries,
        private readonly InquiryBriefRepository $briefs,
        private readonly ClockInterface $clock,
        #[Autowire('%inquiries.retention_months%')]
        private readonly int $retentionMonths,
    ) {
    }

    public function __invoke(PurgeInquiries $command): int
    {
        $cutoff = $this->clock->now()->modify(\sprintf('-%d months', $this->retentionMonths));

        if ($command->dryRun) {
            return $this->inquiries->countCreatedBefore($cutoff);
        }
        $deleted = $this->inquiries->deleteCreatedBefore($cutoff);
        $this->briefs->deleteCreatedBefore($cutoff);

        return $deleted;
    }
}
