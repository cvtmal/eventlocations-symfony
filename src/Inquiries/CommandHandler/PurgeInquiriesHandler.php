<?php

declare(strict_types=1);

namespace App\Inquiries\CommandHandler;

use App\Inquiries\Command\PurgeInquiries;
use App\Inquiries\Repository\InquiryRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * LEG-2. What #29 derives goes with the row: `crm_inquiry_links` cascades, and contacts and
 * activities fall under `app:crm:purge` (CRM-11), so no purge event exists.
 */
final class PurgeInquiriesHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly InquiryRepository $inquiries,
        private readonly ClockInterface $clock,
        #[Autowire('%inquiries.retention_months%')]
        private readonly int $retentionMonths,
    ) {
    }

    public function __invoke(PurgeInquiries $command): int
    {
        $cutoff = $this->clock->now()->modify(\sprintf('-%d months', $this->retentionMonths));

        return $command->dryRun
            ? $this->inquiries->countCreatedBefore($cutoff)
            : $this->inquiries->deleteCreatedBefore($cutoff);
    }
}
