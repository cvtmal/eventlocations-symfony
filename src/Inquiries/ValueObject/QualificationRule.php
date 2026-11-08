<?php

declare(strict_types=1);

namespace App\Inquiries\ValueObject;

/**
 * Whether an inquiry is qualified (D19: delivered successfully and plausible), with the D56 criteria and thresholds.
 * Pure: `QualificationFactsQuery` reads the facts, this decides. Each threshold is one constant.
 *
 * The first reason that applies wins, in this order: not delivered, a duplicate (an earlier delivered, unmarked
 * inquiry from the same address to the same venue within DUPLICATE_WINDOW_DAYS), an event type the venue does not
 * list (only when it lists any), a guest count no venue or room range holds (F5b), an event date more than
 * DATE_HORIZON_MONTHS after the inquiry's day in Zurich. Catering, budget, the address's domain and the phone are no
 * criteria (D56). A flexible date (none given) passes.
 */
final readonly class QualificationRule
{
    public const int DUPLICATE_WINDOW_DAYS = 30;

    public const int DATE_HORIZON_MONTHS = 24;

    public const string ZONE = 'Europe/Zurich';

    /**
     * @return QualificationReason|null null when the inquiry is qualified
     */
    public function evaluate(QualificationFacts $facts): ?QualificationReason
    {
        if (!$facts->delivered) {
            return QualificationReason::NotDelivered;
        }

        if (null !== $facts->previousDeliveredAt && $facts->previousDeliveredAt >= $this->duplicateSince($facts->createdAt)) {
            return QualificationReason::Duplicate;
        }

        if ([] !== $facts->venueEventTypes && !\in_array($facts->eventType, $facts->venueEventTypes, true)) {
            return QualificationReason::EventTypeNotOffered;
        }

        if (!$facts->guestsFit) {
            return QualificationReason::GuestsOutsideCapacity;
        }

        if (null !== $facts->eventDate && $facts->eventDate->format('Y-m-d') > $this->lastDate($facts->createdAt)) {
            return QualificationReason::DateTooFar;
        }

        return null;
    }

    private function duplicateSince(\DateTimeImmutable $createdAt): \DateTimeImmutable
    {
        // In UTC, so the window is exactly that many times 24 hours across a DST change.
        return $createdAt->setTimezone(new \DateTimeZone('UTC'))->sub(new \DateInterval(\sprintf('P%dD', self::DUPLICATE_WINDOW_DAYS)));
    }

    /**
     * @return string the last event date (Y-m-d) that is not too far: the inquiry's Zurich day plus the horizon
     */
    private function lastDate(\DateTimeImmutable $createdAt): string
    {
        $day = new \DateTimeImmutable($createdAt->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d'), new \DateTimeZone('UTC'));

        return $day->add(new \DateInterval(\sprintf('P%dM', self::DATE_HORIZON_MONTHS)))->format('Y-m-d');
    }
}
