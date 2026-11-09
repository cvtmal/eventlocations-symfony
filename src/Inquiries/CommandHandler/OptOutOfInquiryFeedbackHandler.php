<?php

declare(strict_types=1);

namespace App\Inquiries\CommandHandler;

use App\Inquiries\Command\OptOutOfInquiryFeedback;
use App\Inquiries\Exception\InquiryNotFound;
use App\Inquiries\Repository\InquiryFeedbackRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use Psr\Clock\ClockInterface;

/**
 * Marks the address of this inquiry as opted out of the feedback mails (G16); the first opt-out's time stays.
 */
final class OptOutOfInquiryFeedbackHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly InquiryFeedbackRepository $feedback,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(OptOutOfInquiryFeedback $command): void
    {
        $feedback = $this->feedback->lock($command->inquiryId) ?? throw InquiryNotFound::forId($command->inquiryId);
        $feedback->optOut($this->clock->now());
    }
}
