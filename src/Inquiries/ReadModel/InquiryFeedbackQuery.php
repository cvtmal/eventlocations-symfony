<?php

declare(strict_types=1);

namespace App\Inquiries\ReadModel;

use App\Inquiries\ValueObject\FeedbackOutcome;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * One inquiry's feedback row (G16), for the answer page and the CRM detail; null when the inquiry has none.
 */
final class InquiryFeedbackQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function forInquiry(string $inquiryId): ?FeedbackState
    {
        if (!Uuid::isValid($inquiryId)) {
            return null;
        }

        /** @var array{venue_name: string, mailed_at: ?string, opted_out_at: ?string, venue_replied: ?bool, outcome: ?string, answered_at: ?string}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT i.venue_name, f.mailed_at, f.opted_out_at, f.venue_replied, f.outcome, f.answered_at
             FROM inquiry_feedback f JOIN inquiries i ON i.id = f.inquiry_id
             WHERE f.inquiry_id = :id',
            ['id' => $inquiryId],
        );
        if (false === $row) {
            return null;
        }

        return new FeedbackState(
            $inquiryId,
            $row['venue_name'],
            null !== $row['mailed_at'],
            null !== $row['opted_out_at'],
            $row['venue_replied'],
            null === $row['outcome'] ? null : FeedbackOutcome::from($row['outcome']),
            null === $row['answered_at'] ? null : new \DateTimeImmutable($row['answered_at']),
        );
    }
}
