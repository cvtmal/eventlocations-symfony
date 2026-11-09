<?php

declare(strict_types=1);

namespace App\Inquiries;

use App\Inquiries\Command\SubmitInquiry;
use App\Inquiries\Exception\BudgetOutOfRange;
use App\Inquiries\Exception\EventDateInPast;
use App\Inquiries\Exception\EventDateMissing;
use App\Inquiries\Exception\GuestCountOutOfRange;
use App\Inquiries\Exception\UnknownInquiryTerm;
use App\Inquiries\ReadModel\InquiryVocabularyQuery;

/**
 * The business rules of what the guest typed (the handler layer of the validation strategy), shared by a single
 * inquiry and every child of a brief (F13). "Today" is the Swiss calendar day, whatever the server's clock says.
 */
final readonly class InquiryRules
{
    private const TIMEZONE = 'Europe/Zurich';
    private const MIN_GUESTS = 1;
    private const MAX_GUESTS = 5000;
    private const MIN_BUDGET_CHF = 1;
    private const MAX_BUDGET_CHF = 1_000_000;

    public function __construct(
        private InquiryVocabularyQuery $vocabulary,
    ) {
    }

    /**
     * @throws GuestCountOutOfRange|UnknownInquiryTerm|EventDateMissing|BudgetOutOfRange|EventDateInPast
     */
    public function check(SubmitInquiry $command, \DateTimeImmutable $now): void
    {
        if ($command->guests < self::MIN_GUESTS || $command->guests > self::MAX_GUESTS) {
            throw GuestCountOutOfRange::for($command->guests);
        }
        if (!$this->vocabulary->isEventType($command->eventType)) {
            throw UnknownInquiryTerm::eventType($command->eventType);
        }
        if (null !== $command->cateringWish && !$this->vocabulary->isCateringWish($command->cateringWish)) {
            throw UnknownInquiryTerm::cateringWish($command->cateringWish);
        }
        if (null === $command->eventDate && !$command->dateFlexible) {
            throw EventDateMissing::create();
        }
        if (null !== $command->budgetChf && ($command->budgetChf < self::MIN_BUDGET_CHF || $command->budgetChf > self::MAX_BUDGET_CHF)) {
            throw BudgetOutOfRange::for($command->budgetChf);
        }
        if (null !== $command->eventDate && $command->eventDate < $now->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d')) {
            throw EventDateInPast::for($command->eventDate);
        }
    }

    /**
     * @throws \InvalidArgumentException when the command breaks its own `H:i` contract (the form never does)
     */
    public static function startTime(?string $time): ?\DateTimeImmutable
    {
        if (null === $time) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!H:i', $time);
        if (false === $parsed || $parsed->format('H:i') !== $time) {
            throw new \InvalidArgumentException(\sprintf('"%s" is no start time of the form H:i.', $time));
        }

        return $parsed;
    }
}
