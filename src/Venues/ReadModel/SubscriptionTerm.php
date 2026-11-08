<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

/**
 * One paid term (G9) as the CRM, `/konto` and the cancellation page show it. `paidUntil` is the last day of the
 * venue's latest term, which is later than `endsOn` once the next term is paid.
 */
final readonly class SubscriptionTerm
{
    public function __construct(
        public string $id,
        public string $venueId,
        public int $venueNumber,
        public string $venueName,
        public string $tierCode,
        public string $tierName,
        public \DateTimeImmutable $startsOn,
        public \DateTimeImmutable $endsOn,
        public ?\DateTimeImmutable $cancelRequestedAt,
        public \DateTimeImmutable $paidUntil,
    ) {
    }

    public function isCancelled(): bool
    {
        return null !== $this->cancelRequestedAt;
    }

    public function hasEndedBefore(\DateTimeImmutable $day): bool
    {
        return $this->endsOn < $day;
    }
}
