<?php

declare(strict_types=1);

namespace App\Stats\EventListener;

use App\Inquiries\Event\InquirySubmitted;
use App\Stats\ReadModel\InquiryVenueQuery;
use App\Stats\Spool;
use App\Stats\ValueObject\SpoolEvent;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Counts a sent inquiry (#47): one `inquiry` spool line with the venue's number, nothing for an
 * inquiry without a venue. It runs after the commits, outside any transaction. A failure is logged
 * and swallowed here, not left to `InquiryEvents`: a throwing subscriber would stop the CRM's
 * listener of the same event. The log names the class, never the message.
 */
#[AsEventListener(event: InquirySubmitted::class)]
final class RecordInquiryListener
{
    public function __construct(
        private readonly InquiryVenueQuery $venues,
        private readonly Spool $spool,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(InquirySubmitted $event): void
    {
        try {
            $number = $this->venues->numberOf($event->inquiryId);
            if (null !== $number) {
                $this->spool->append([SpoolEvent::inquiry($this->clock->now(), $number)]);
            }
        } catch (\Throwable $exception) {
            // The values go into placeholders: the default logger prints nothing else of the context.
            $this->logger->error('Inquiry {inquiry}: recording it for the statistics failed ({exception_class} at {file}:{line}).', [
                'inquiry' => $event->inquiryId,
                'exception_class' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
        }
    }
}
