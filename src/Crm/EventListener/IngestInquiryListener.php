<?php

declare(strict_types=1);

namespace App\Crm\EventListener;

use App\Crm\Command\IngestInquiry;
use App\Inquiries\Event\InquirySubmitted;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * An inquiry reaches the CRM here, and nothing that goes wrong may reach the guest (CRM-9). The failure is logged and
 * `app:crm:backfill-inquiries` picks the row up at night, because no link row was written. The log names the class,
 * never the message: a driver message can quote an address.
 */
#[AsEventListener(event: InquirySubmitted::class)]
final class IngestInquiryListener
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(InquirySubmitted $event): void
    {
        try {
            $this->commandBus->dispatch(new IngestInquiry($event->inquiryId));
        } catch (\Throwable $failure) {
            $exception = $failure instanceof HandlerFailedException ? (array_values($failure->getWrappedExceptions())[0] ?? $failure) : $failure;
            // The values go into placeholders: the default logger prints nothing else of the context.
            $this->logger->error('Inquiry {inquiry}: CRM ingestion failed ({exception_class} at {file}:{line}).', [
                'inquiry' => $event->inquiryId,
                'exception_class' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
        }
    }
}
