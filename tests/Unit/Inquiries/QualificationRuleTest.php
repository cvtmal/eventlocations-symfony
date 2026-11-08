<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries;

use App\Inquiries\ValueObject\QualificationFacts;
use App\Inquiries\ValueObject\QualificationReason;
use App\Inquiries\ValueObject\QualificationRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QualificationRuleTest extends TestCase
{
    private const string CREATED = '2026-11-08T10:00:00+00:00';

    public function test_a_delivered_plausible_inquiry_is_qualified(): void
    {
        self::assertNull($this->evaluate());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, QualificationReason}>
     */
    public static function singleReasons(): iterable
    {
        yield 'not delivered' => [['delivered' => false], QualificationReason::NotDelivered];
        yield 'duplicate' => [['previous' => '2026-10-29T10:00:00+00:00'], QualificationReason::Duplicate];
        yield 'event type not offered' => [['eventType' => 'wedding'], QualificationReason::EventTypeNotOffered];
        yield 'guests outside capacity' => [['guestsFit' => false], QualificationReason::GuestsOutsideCapacity];
        yield 'date too far' => [['eventDate' => '2028-12-01'], QualificationReason::DateTooFar];
    }

    /**
     * @param array<string, mixed> $facts
     */
    #[DataProvider('singleReasons')]
    public function test_each_reason_alone(array $facts, QualificationReason $reason): void
    {
        self::assertSame($reason, $this->evaluate($facts));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, QualificationReason}>
     */
    public static function priorities(): iterable
    {
        yield 'not delivered before duplicate' => [['delivered' => false, 'previous' => '2026-11-01T10:00:00+00:00'], QualificationReason::NotDelivered];
        yield 'duplicate before event type' => [['previous' => '2026-11-01T10:00:00+00:00', 'eventType' => 'wedding'], QualificationReason::Duplicate];
        yield 'event type before guests' => [['eventType' => 'wedding', 'guestsFit' => false], QualificationReason::EventTypeNotOffered];
        yield 'guests before date' => [['guestsFit' => false, 'eventDate' => '2030-01-01'], QualificationReason::GuestsOutsideCapacity];
    }

    /**
     * @param array<string, mixed> $facts
     */
    #[DataProvider('priorities')]
    public function test_the_first_reason_in_priority_order_wins(array $facts, QualificationReason $reason): void
    {
        self::assertSame($reason, $this->evaluate($facts));
    }

    public function test_an_earlier_delivery_exactly_thirty_days_back_is_a_duplicate(): void
    {
        self::assertSame(QualificationReason::Duplicate, $this->evaluate(['previous' => '2026-10-09T10:00:00+00:00']));
    }

    public function test_an_earlier_delivery_a_second_more_than_thirty_days_back_is_not(): void
    {
        self::assertNull($this->evaluate(['previous' => '2026-10-09T09:59:59+00:00']));
    }

    public function test_the_duplicate_window_counts_real_hours_across_the_dst_change(): void
    {
        // Zurich left summer time on 2026-10-25: 30 days before 10:00 UTC are still 10:00 UTC, in any zone.
        self::assertSame(QualificationReason::Duplicate, $this->evaluate(['previous' => '2026-10-09T12:00:00+02:00']));
        self::assertNull($this->evaluate(['previous' => '2026-10-09T11:59:59+02:00']));
    }

    public function test_an_event_date_exactly_the_horizon_ahead_is_qualified(): void
    {
        self::assertNull($this->evaluate(['eventDate' => '2028-11-08']));
    }

    public function test_an_event_date_a_day_beyond_the_horizon_is_too_far(): void
    {
        self::assertSame(QualificationReason::DateTooFar, $this->evaluate(['eventDate' => '2028-11-09']));
    }

    public function test_the_horizon_starts_from_the_inquiry_day_in_zurich(): void
    {
        // 23:30 UTC on 2026-11-08 is already 2026-11-09 in Zurich.
        $facts = ['created' => '2026-11-08T23:30:00+00:00'];

        self::assertNull($this->evaluate([...$facts, 'eventDate' => '2028-11-09']));
        self::assertSame(QualificationReason::DateTooFar, $this->evaluate([...$facts, 'eventDate' => '2028-11-10']));
    }

    public function test_a_flexible_date_passes(): void
    {
        self::assertNull($this->evaluate(['eventDate' => null]));
    }

    public function test_a_venue_that_lists_no_event_types_accepts_any(): void
    {
        self::assertNull($this->evaluate(['venueEventTypes' => [], 'eventType' => 'wedding']));
    }

    public function test_the_thresholds_are_the_d56_values(): void
    {
        self::assertSame(30, QualificationRule::DUPLICATE_WINDOW_DAYS);
        self::assertSame(24, QualificationRule::DATE_HORIZON_MONTHS);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function evaluate(array $overrides = []): ?QualificationReason
    {
        $o = [
            'delivered' => true,
            'created' => self::CREATED,
            'eventDate' => '2027-06-15',
            'eventType' => 'seminar',
            'venueEventTypes' => ['seminar', 'corporate_event'],
            'guestsFit' => true,
            'previous' => null,
            ...$overrides,
        ];
        \assert(\is_bool($o['delivered']) && \is_string($o['created']) && \is_string($o['eventType']) && \is_bool($o['guestsFit']));
        \assert(null === $o['eventDate'] || \is_string($o['eventDate']));
        \assert(null === $o['previous'] || \is_string($o['previous']));
        /** @var list<string> $types */
        $types = $o['venueEventTypes'];

        return (new QualificationRule())->evaluate(new QualificationFacts(
            $o['delivered'],
            new \DateTimeImmutable($o['created']),
            null === $o['eventDate'] ? null : new \DateTimeImmutable($o['eventDate']),
            $o['eventType'],
            $types,
            $o['guestsFit'],
            null === $o['previous'] ? null : new \DateTimeImmutable($o['previous']),
        ));
    }
}
