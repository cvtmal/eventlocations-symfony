<?php

declare(strict_types=1);

namespace App\Inquiries\CommandHandler;

use App\Inquiries\Command\RequestInquiryFeedback;
use App\Inquiries\Entity\Inquiry;
use App\Inquiries\Entity\InquiryFeedback;
use App\Inquiries\Mailer\FeedbackMailer;
use App\Inquiries\Repository\InquiryFeedbackRepository;
use App\Inquiries\Repository\InquiryRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * One feedback mail per address and run (G16). The rows are written only after the mail went out, so a failed mail
 * writes nothing and the next night tries again (inside `FeedbackRequestsDue`'s window); a crash between the SMTP
 * acknowledgement and the commit would send it twice, like the stuck alert. An inquiry that already has a row is
 * left out, so a second run sends and writes nothing.
 */
final class RequestInquiryFeedbackHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly InquiryRepository $inquiries,
        private readonly InquiryFeedbackRepository $feedback,
        private readonly FeedbackMailer $mailer,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool false when the mail failed
     */
    public function __invoke(RequestInquiryFeedback $command): bool
    {
        // An inquiry purged since the read is skipped, not an error that would stop the run.
        $inquiries = array_values(array_filter(array_map(
            fn (string $id): ?Inquiry => $this->feedback->has($id) ? null : $this->inquiries->find($id),
            $command->inquiryIds,
        )));
        if ([] === $inquiries) {
            return true;
        }

        if (!$command->optedOut) {
            try {
                $this->mailer->sendFeedbackRequest($inquiries);
            } catch (TransportExceptionInterface|HandlerFailedException|RfcComplianceException $exception) {
                $cause = $exception instanceof HandlerFailedException ? $exception->getPrevious() ?? $exception : $exception;
                $this->logger->error('The {mail} mail for inquiry {inquiry} failed ({exception_class} at {file}:{line}).', [
                    'mail' => 'feedback',
                    'inquiry' => $inquiries[0]->id(),
                    'exception_class' => $cause::class,
                    'file' => $cause->getFile(),
                    'line' => $cause->getLine(),
                ]);

                return false;
            }
        }

        $now = $this->clock->now();
        foreach ($inquiries as $inquiry) {
            $this->feedback->add($command->optedOut ? InquiryFeedback::skipped($inquiry, $now) : InquiryFeedback::mailed($inquiry, $now));
        }

        return true;
    }
}
