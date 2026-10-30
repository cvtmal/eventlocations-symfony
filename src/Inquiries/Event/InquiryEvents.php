<?php

declare(strict_types=1);

namespace App\Inquiries\Event;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Publishes `InquirySubmitted` so #29 can subscribe without Inquiries knowing the CRM.
 *
 * Deviation from the cycle's wording ("the handler publishes it after the transaction
 * commits"): a handler cannot run code after its own `doctrine_transaction` commit. The
 * controller calls this service after `SubmitInquiry` and `DeliverInquiry` have returned, so
 * after both commits and outside any transaction. Nothing a subscriber does may fail or 5xx
 * the guest (CRM-9), so everything is caught here and logged; the catch is per dispatch, so a
 * throwing subscriber does stop later subscribers of the same event.
 */
final readonly class InquiryEvents
{
    public function __construct(
        private EventDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function submitted(string $inquiryId): void
    {
        try {
            $this->dispatcher->dispatch(new InquirySubmitted($inquiryId));
        } catch (\Throwable $exception) {
            // The values go into placeholders: the default logger prints nothing else of the context.
            $this->logger->error('Inquiry {inquiry}: an InquirySubmitted subscriber failed ({exception_class} at {file}:{line}).', [
                'inquiry' => $inquiryId,
                'exception_class' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
        }
    }
}
