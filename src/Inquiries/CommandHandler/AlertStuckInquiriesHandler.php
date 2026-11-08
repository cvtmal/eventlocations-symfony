<?php

declare(strict_types=1);

namespace App\Inquiries\CommandHandler;

use App\Inquiries\Command\AlertStuckInquiries;
use App\Inquiries\Mailer\InquiryMailer;
use App\Inquiries\ReadModel\StuckInquiries;
use App\Inquiries\ReadModel\StuckInquiry;
use App\Inquiries\Repository\InquiryRepository;
use App\Inquiries\ValueObject\StuckAlert;
use App\Shared\Messenger\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * The hourly staff alert (G7, D56). The rows are marked `stuck_alerted_at` only after the mail went out: a failed
 * mail writes nothing, so the next run retries, and a sent one leaves nothing for the next run, which then sends
 * and writes nothing (Wave G §4a.5). A marker beats a "became stuck in the last hour" window, which would lose rows
 * after a missed run and report them twice after an overlapping one.
 */
final class AlertStuckInquiriesHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly StuckInquiries $stuckInquiries,
        private readonly InquiryMailer $mailer,
        private readonly InquiryRepository $inquiries,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(AlertStuckInquiries $command): StuckAlert
    {
        $rows = $this->stuckInquiries->due($command->pendingBefore, $command->createdSince);
        if ([] === $rows) {
            return new StuckAlert([], true);
        }

        try {
            $this->mailer->sendStuckAlert($rows);
        } catch (TransportExceptionInterface|HandlerFailedException $exception) {
            $cause = $exception instanceof HandlerFailedException ? $exception->getPrevious() ?? $exception : $exception;
            $this->logger->error('The {mail} mail to staff failed ({exception_class} at {file}:{line}).', [
                'mail' => 'stuck',
                'exception_class' => $cause::class,
                'file' => $cause->getFile(),
                'line' => $cause->getLine(),
            ]);

            return new StuckAlert($rows, false);
        }

        $this->inquiries->recordStuckAlerted(array_map(static fn (StuckInquiry $row): string => $row->id, $rows), $this->clock->now());

        return new StuckAlert($rows, true);
    }
}
