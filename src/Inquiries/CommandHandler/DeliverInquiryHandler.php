<?php

declare(strict_types=1);

namespace App\Inquiries\CommandHandler;

use App\Inquiries\Command\ConfirmInquiryBrief;
use App\Inquiries\Command\DeliverInquiry;
use App\Inquiries\Mailer\InquiryMailer;
use App\Inquiries\ReadModel\QualificationFactsQuery;
use App\Inquiries\Repository\InquiryRepository;
use App\Inquiries\ValueObject\DeliveryOutcome;
use App\Inquiries\ValueObject\MailStatus;
use App\Inquiries\ValueObject\QualificationRule;
use App\Shared\Command\RecordFunnelEvent;
use App\Shared\Funnel\FunnelEvent;
use App\Shared\Messenger\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * Sends the venue mail and, only if that went out, the guest copy, and records both outcomes
 * (IQ-1). A mail failure is an outcome, not an error: it is caught per mail, so the statuses
 * commit. The mailer unwraps only transport errors; anything else that fails in its pipeline is
 * a `HandlerFailedException`, for example an address the SMTP server cannot carry.
 * `chk_venues_inquiry_email` admits values `Address` refuses at build time, hence the second type.
 *
 * Before the venue mail any other error bubbles: nothing has left, and the row stays `pending`.
 * After it nothing may undo the record, so the guest copy catches everything, including a render
 * error, which the mailer raises unwrapped.
 *
 * The handler claims the row, and the claim lasts until the commit, across both SMTP calls. So
 * deliveries of one row run in sequence, a repeated POST or a later resend alike: the second one
 * waits, then reads what the first recorded. A row recorded as `sent` is not mailed again, and
 * that includes its guest copy, even a `failed` one. `failed` delivers again, and so does
 * `pending`, because a claimed `pending` means the earlier delivery is over without a result.
 * The record is the limit of this: a process death or a lost database connection after SMTP
 * accepted the venue mail and before the commit leaves `pending`, and the next delivery mails
 * the venue again. A lost inquiry would be worse.
 *
 * Log text carries the mail, the id and where the cause was raised, never its message, which can
 * quote an address. The values go into placeholders, because the default logger prints nothing
 * else of the context.
 *
 * The first successful venue mail is the funnel step `inquiry_delivered` (G8), recorded once per inquiry (a
 * `sent` row returns above) and after the commit, so it can neither undo the record nor fail the guest. An inquiry
 * staff have marked spam or test (G7) records no step when a resend delivers it (D56).
 *
 * Each delivery also stores the D19 qualification (G7, `QualificationRule`): a snapshot of this delivery, recomputed
 * on every resend. Its facts are read before the venue mail, so nothing that runs after the mail can fail.
 */
final class DeliverInquiryHandler implements CommandHandlerInterface
{
    private readonly QualificationRule $rule;

    public function __construct(
        private readonly InquiryRepository $inquiries,
        private readonly InquiryMailer $mailer,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $commandBus,
        private readonly QualificationFactsQuery $qualificationFacts,
    ) {
        $this->rule = new QualificationRule();
    }

    public function __invoke(DeliverInquiry $command): DeliveryOutcome
    {
        $inquiry = $this->inquiries->claim($command->inquiryId);
        if (MailStatus::Sent === $inquiry->venueMailStatus()) {
            return new DeliveryOutcome(true);
        }

        $facts = $this->qualificationFacts->facts(
            $inquiry->id(),
            $inquiry->venue()?->id(),
            $inquiry->email(),
            $inquiry->createdAt(),
            $inquiry->guestCount(),
            $inquiry->eventType(),
            $inquiry->eventDate(),
        );

        try {
            $deliveredTo = $this->mailer->sendToVenue($inquiry);
        } catch (TransportExceptionInterface|RfcComplianceException|HandlerFailedException $exception) {
            $now = $this->clock->now();
            $inquiry->recordVenueMailFailed($now);
            $inquiry->recordGuestMail(MailStatus::Skipped, $now);
            $inquiry->recordQualification($this->rule->evaluate($facts->withDelivered(false)), $now);
            $this->logFailure('venue', $inquiry->id(), $exception);

            return new DeliveryOutcome(false);
        }

        $now = $this->clock->now();
        $inquiry->recordVenueMailSent($deliveredTo, $now);
        $inquiry->recordQualification($this->rule->evaluate($facts->withDelivered(true)), $now);
        if (null === $inquiry->qualificationOverride()) {
            $this->commandBus->dispatch(new RecordFunnelEvent(FunnelEvent::InquiryDelivered, $inquiry->venue()?->id()), [new DispatchAfterCurrentBusStamp()]);
        }

        // A child of a brief (F13): the guest gets the brief's one confirmation (`ConfirmInquiryBrief`), no copy per venue.
        // Once no sibling waits for its first delivery, the brief's confirmation follows after the commit; a staff resend
        // of a brief that reached no venue thereby confirms it late, and a confirmation sent already is never repeated.
        $brief = $inquiry->brief();
        if (null !== $brief) {
            $inquiry->recordGuestMail(MailStatus::Skipped, $this->clock->now());
            if (MailStatus::Pending === $brief->guestMailStatus() && !$this->inquiries->hasPendingSibling($brief->id(), $inquiry->id())) {
                $this->commandBus->dispatch(new ConfirmInquiryBrief($brief->id()), [new DispatchAfterCurrentBusStamp()]);
            }

            return new DeliveryOutcome(true);
        }

        try {
            $this->mailer->sendToGuest($inquiry);
            $inquiry->recordGuestMail(MailStatus::Sent, $this->clock->now());
        } catch (\Throwable $exception) {
            $inquiry->recordGuestMail(MailStatus::Failed, $this->clock->now());
            $this->logFailure('guest', $inquiry->id(), $exception);
        }

        return new DeliveryOutcome(true);
    }

    private function logFailure(string $mail, string $inquiryId, \Throwable $exception): void
    {
        $cause = $exception instanceof HandlerFailedException ? $exception->getPrevious() ?? $exception : $exception;
        $this->logger->error('Inquiry {inquiry}: the {mail} mail failed ({exception_class} at {file}:{line}).', [
            'inquiry' => $inquiryId,
            'mail' => $mail,
            'exception_class' => $cause::class,
            'file' => $cause->getFile(),
            'line' => $cause->getLine(),
        ]);
    }
}
