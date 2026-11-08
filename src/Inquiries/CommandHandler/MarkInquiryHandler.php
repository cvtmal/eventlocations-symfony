<?php

declare(strict_types=1);

namespace App\Inquiries\CommandHandler;

use App\Inquiries\Command\MarkInquiry;
use App\Inquiries\Exception\InquiryNotFound;
use App\Inquiries\Repository\InquiryRepository;
use App\Inquiries\ValueObject\MailStatus;
use App\Shared\Command\RecordFunnelEvent;
use App\Shared\Command\WithdrawFunnelEvent;
use App\Shared\Funnel\FunnelEvent;
use App\Shared\Messenger\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Sets or removes the staff mark of an inquiry (D19, G7). A marked inquiry is no delivered funnel step (D56): marking
 * a delivered inquiry withdraws its `inquiry_delivered` step, unmarking records it again, at the time of the unmark.
 * Spam to test or back touches no step, and neither does marking an inquiry that was never delivered.
 *
 * The funnel log keeps no inquiry id, so the withdraw names the venue and `venue_mail_sent_at`: `WithdrawFunnelEvent`
 * removes the venue's step nearest that time within an hour, and `DeliverInquiryHandler` records the step a moment
 * after it. Two deliveries to one venue within the same hour may swap which of their two rows goes (the count stays
 * right); after a mark, an unmark and a mark more than an hour apart the second withdraw may miss the step the unmark
 * recorded. Accepted for at most two staff users.
 */
final class MarkInquiryHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly InquiryRepository $inquiries,
        private readonly ClockInterface $clock,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(MarkInquiry $command): void
    {
        $inquiry = $this->inquiries->find($command->inquiryId) ?? throw InquiryNotFound::forId($command->inquiryId);
        $previous = $inquiry->mark($command->override, $this->clock->now());

        $venueId = $inquiry->venue()?->id();
        $sentAt = $inquiry->venueMailSentAt();
        if (MailStatus::Sent !== $inquiry->venueMailStatus() || null === $venueId || null === $sentAt) {
            return;
        }

        if (null === $previous && null !== $command->override) {
            $this->commandBus->dispatch(new WithdrawFunnelEvent(FunnelEvent::InquiryDelivered, $venueId, $sentAt), [new DispatchAfterCurrentBusStamp()]);
        } elseif (null !== $previous && null === $command->override) {
            $this->commandBus->dispatch(new RecordFunnelEvent(FunnelEvent::InquiryDelivered, $venueId), [new DispatchAfterCurrentBusStamp()]);
        }
    }
}
