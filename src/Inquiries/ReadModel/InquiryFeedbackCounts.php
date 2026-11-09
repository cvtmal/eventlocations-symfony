<?php

declare(strict_types=1);

namespace App\Inquiries\ReadModel;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * «x Rückmeldungen» of a venue's monthly report (G16, for G10): how many guests answered the feedback mail in that
 * Europe/Zurich calendar month (by the first answer), whatever they answered. Never a number of bookings. An inquiry
 * staff later mark spam or test leaves the count, like every qualified figure (D56).
 */
final class InquiryFeedbackCounts
{
    private const string ZONE = 'Europe/Zurich';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param \DateTimeImmutable $month any moment in the month
     */
    public function forVenue(string $venueId, \DateTimeImmutable $month): int
    {
        $start = $month->setTimezone(new \DateTimeZone(self::ZONE))->modify('first day of this month')->setTime(0, 0);

        $qualified = QualifiedInquirySql::where('i');

        return (int) $this->connection->fetchOne(
            "SELECT count(*) FROM inquiry_feedback f JOIN inquiries i ON i.id = f.inquiry_id
             WHERE i.venue_id = :venue AND {$qualified} AND f.answered_at >= :start AND f.answered_at < :end",
            ['venue' => $venueId, 'start' => $start, 'end' => $start->modify('first day of next month')],
            ['start' => Types::DATETIMETZ_IMMUTABLE, 'end' => Types::DATETIMETZ_IMMUTABLE],
        );
    }
}
