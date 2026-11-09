<?php

declare(strict_types=1);

namespace App\Inquiries\CommandHandler;

use App\Inquiries\Command\SubmitInquiryBrief;
use App\Inquiries\Entity\Inquiry;
use App\Inquiries\Entity\InquiryBrief;
use App\Inquiries\Exception\BriefNotAccepted;
use App\Inquiries\InquiryRules;
use App\Inquiries\Repository\InquiryBriefRepository;
use App\Inquiries\Repository\InquiryRepository;
use App\Inquiries\ValueObject\BriefRules;
use App\Inquiries\ValueObject\InquiryFingerprint;
use App\Inquiries\ValueObject\SubmittedBrief;
use App\Shared\Command\RecordFunnelEvent;
use App\Shared\Funnel\FunnelEvent;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Repository\VenueRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Stores a brief to several venues (F13, D17): the parent and one child inquiry per ticked venue, all or nothing.
 *
 * The guest saw each venue named before sending, so the brief goes to exactly those or to none: a venue that no longer
 * takes briefs (gone, unpublished, no address, opted out, today's cap reached) refuses the whole brief, and the page
 * shows the current choice again. The cap is counted under a lock per venue, taken in id order, so two briefs at once
 * cannot both take the last place and cannot deadlock.
 *
 * A double submit returns the brief it repeats (fingerprint over the venues and the payload, like `SubmitInquiry`),
 * before the venue rules, so a repeat is never refused by its own children. The funnel step `brief_sent` is recorded
 * once per new brief, after the commit; each child still records its own `inquiry_delivered` when its mail goes out.
 */
final class SubmitInquiryBriefHandler implements CommandHandlerInterface
{
    private const REPEAT_WINDOW = '-10 minutes';

    public function __construct(
        private readonly VenueRepository $venues,
        private readonly InquiryRepository $inquiries,
        private readonly InquiryBriefRepository $briefs,
        private readonly InquiryRules $rules,
        private readonly ClockInterface $clock,
        private readonly MessageBusInterface $commandBus,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    public function __invoke(SubmitInquiryBrief $command): SubmittedBrief
    {
        $count = \count($command->venues);
        if ($count < BriefRules::MIN_VENUES || $count > BriefRules::MAX_VENUES) {
            throw BriefNotAccepted::venueCount($count);
        }

        $children = [];
        foreach ($command->venues as $venueId => $slug) {
            $children[] = $command->forVenue((string) $venueId, $slug);
        }
        $now = $this->clock->now();
        $this->rules->check($children[0], $now);

        $fingerprints = array_map(fn ($child): string => InquiryFingerprint::of($child, $this->secret)->value, $children);
        $sorted = $fingerprints;
        sort($sorted);
        $fingerprint = hash_hmac('sha256', 'brief|'.implode('|', $sorted), $this->secret);
        $this->inquiries->lockFingerprint($fingerprint);

        $repeated = $this->briefs->findLatestIdByFingerprint($fingerprint, $now->modify(self::REPEAT_WINDOW));
        if (null !== $repeated) {
            $ids = array_map(static fn (Inquiry $child): string => $child->id(), $this->inquiries->childrenOf($repeated));
            \assert([] !== $ids);

            return new SubmittedBrief($repeated, $ids, true);
        }

        $venueIds = array_map('strval', array_keys($command->venues));
        $locked = $venueIds;
        sort($locked);
        foreach ($locked as $venueId) {
            $this->inquiries->lockVenueForBriefs($venueId);
        }
        $dayStart = BriefRules::dayStart($now);
        $venues = [];
        foreach ($venueIds as $venueId) {
            $venue = $this->venues->find($venueId);
            if (null === $venue || !$venue->isPublished() || null === $venue->inquiryEmail() || !$venue->acceptsMultiInquiries()
                || $this->inquiries->countBriefChildrenSince($venueId, $dayStart) >= BriefRules::DAILY_CAP_PER_VENUE) {
                throw BriefNotAccepted::forVenue($venueId);
            }
            $venues[] = $venue;
        }

        $brief = new InquiryBrief($count, $fingerprint, $now);
        $this->briefs->add($brief);
        $ids = [];
        foreach ($children as $i => $child) {
            $inquiry = new Inquiry(
                $venues[$i],
                $child->venueSlug,
                $child->locale,
                $child->eventType,
                $child->company,
                $child->guests,
                null === $child->eventDate ? null : new \DateTimeImmutable($child->eventDate),
                $child->dateFlexible,
                InquiryRules::startTime($child->startTime),
                $child->cateringWish,
                $child->budgetChf,
                $child->message,
                $child->firstName,
                $child->lastName,
                $child->email,
                $child->phone,
                $fingerprints[$i],
                $now,
                $brief,
            );
            $this->inquiries->add($inquiry);
            $ids[] = $inquiry->id();
        }

        $this->commandBus->dispatch(new RecordFunnelEvent(FunnelEvent::BriefSent), [new DispatchAfterCurrentBusStamp()]);

        return new SubmittedBrief($brief->id(), $ids, false);
    }
}
