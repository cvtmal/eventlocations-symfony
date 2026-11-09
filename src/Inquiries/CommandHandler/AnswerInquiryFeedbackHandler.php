<?php

declare(strict_types=1);

namespace App\Inquiries\CommandHandler;

use App\Inquiries\Command\AnswerInquiryFeedback;
use App\Inquiries\Exception\InquiryNotFound;
use App\Inquiries\Repository\InquiryFeedbackRepository;
use App\Shared\Messenger\CommandHandlerInterface;
use Psr\Clock\ClockInterface;

/**
 * Stores the guest's answer (G16). Only a mailed row takes one: a row recorded without a mail had no link.
 */
final class AnswerInquiryFeedbackHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly InquiryFeedbackRepository $feedback,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(AnswerInquiryFeedback $command): void
    {
        $feedback = $this->feedback->lock($command->inquiryId);
        if (null === $feedback || !$feedback->wasMailed()) {
            throw InquiryNotFound::forId($command->inquiryId);
        }

        $feedback->answer($command->venueReplied, $command->outcome, $this->clock->now());
    }
}
