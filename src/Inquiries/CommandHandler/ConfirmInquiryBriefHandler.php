<?php

declare(strict_types=1);

namespace App\Inquiries\CommandHandler;

use App\Inquiries\Command\ConfirmInquiryBrief;
use App\Inquiries\Entity\Inquiry;
use App\Inquiries\Mailer\InquiryMailer;
use App\Inquiries\Repository\InquiryBriefRepository;
use App\Inquiries\Repository\InquiryRepository;
use App\Inquiries\ValueObject\BriefOutcome;
use App\Inquiries\ValueObject\MailStatus;
use App\Shared\Messenger\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * The one confirmation of a brief (F13): sent once at least one venue mail went out, naming the venues reached and
 * those still waiting for staff. Like the guest copy of a single inquiry, nothing about it may undo the record, so a
 * failure is caught and stored as `failed`. A brief whose confirmation is sent is not mailed again (a repeated POST
 * waits on the claim and reads it); one where no venue mail went out stays `pending` and mails nothing, because the
 * guest's page says it was stored and staff deliver it (G7).
 */
final class ConfirmInquiryBriefHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly InquiryBriefRepository $briefs,
        private readonly InquiryRepository $inquiries,
        private readonly InquiryMailer $mailer,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ConfirmInquiryBrief $command): BriefOutcome
    {
        $brief = $this->briefs->claim($command->briefId);
        $children = $this->inquiries->childrenOf($brief->id());
        $reached = array_values(array_filter($children, static fn (Inquiry $child): bool => MailStatus::Sent === $child->venueMailStatus()));
        $undelivered = array_values(array_filter($children, static fn (Inquiry $child): bool => MailStatus::Sent !== $child->venueMailStatus()));
        $outcome = new BriefOutcome(
            array_map(static fn (Inquiry $child): string => $child->venueName(), $reached),
            array_map(static fn (Inquiry $child): string => $child->venueName(), $undelivered),
        );
        if ([] === $reached || MailStatus::Pending !== $brief->guestMailStatus()) {
            return $outcome;
        }

        try {
            $this->mailer->sendBriefConfirmation($reached, $undelivered);
            $brief->recordGuestMail(MailStatus::Sent, $this->clock->now());
        } catch (\Throwable $exception) {
            $brief->recordGuestMail(MailStatus::Failed, $this->clock->now());
            $cause = $exception instanceof HandlerFailedException ? $exception->getPrevious() ?? $exception : $exception;
            $this->logger->error('Brief {brief}: the confirmation mail failed ({exception_class} at {file}:{line}).', [
                'brief' => $brief->id(),
                'exception_class' => $cause::class,
                'file' => $cause->getFile(),
                'line' => $cause->getLine(),
            ]);
        }

        return $outcome;
    }
}
