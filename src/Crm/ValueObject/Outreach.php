<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * What the CRM records about the outreach to one Location (G11, CMO §6.5): the postal campaign it is part of, its
 * pilot cohort, the channel and its basis, the day the letter or mail went out and the day a letter came back.
 * Every field may be empty. The campaign code is a plain `Shared\Funnel\CampaignCode`, checked where staff type it.
 */
final readonly class Outreach
{
    public function __construct(
        public ?string $campaignCode = null,
        public ?OutreachCohort $cohort = null,
        public ?ContactChannel $channel = null,
        public ?ContactBasis $basis = null,
        public ?\DateTimeImmutable $sentOn = null,
        public ?\DateTimeImmutable $returnedOn = null,
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->campaignCode === $other->campaignCode
            && $this->cohort === $other->cohort
            && $this->channel === $other->channel
            && $this->basis === $other->basis
            && $this->sentOn?->format('Y-m-d') === $other->sentOn?->format('Y-m-d')
            && $this->returnedOn?->format('Y-m-d') === $other->returnedOn?->format('Y-m-d');
    }
}
