<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\SubscriptionStatus;
use App\Venues\ValueObject\TermDay;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The paid terms of `subscriptions` (G9), read through DBAL. What a venue may do is `EntitlementResolver`'s answer;
 * this is the term itself: its dates, its cancellation, and which venues the nightly job must look at.
 */
final class SubscriptionTermQuery
{
    private const string SELECT = <<<'SQL'
        SELECT s.id, s.venue_id, v.number AS venue_number, v.name AS venue_name, t.code AS tier_code, t.name AS tier_name,
               s.starts_on, s.ends_on, s.cancel_requested_at,
               (SELECT max(o.ends_on) FROM subscriptions o WHERE o.venue_id = s.venue_id) AS paid_until
        FROM subscriptions s
        JOIN venues v ON v.id = s.venue_id
        JOIN tiers t ON t.id = s.tier_id
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function byId(string $subscriptionId): ?SubscriptionTerm
    {
        if (!Uuid::isValid($subscriptionId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(self::SELECT.' WHERE s.id = :id', ['id' => $subscriptionId]);

        return false === $row ? null : self::term($row);
    }

    /**
     * The term that covers the Zurich day of `$now`, or null on a day without one (the venue is on Free).
     */
    public function current(string $venueId, \DateTimeImmutable $now): ?SubscriptionTerm
    {
        if (!Uuid::isValid($venueId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            self::SELECT.' WHERE s.venue_id = :venue AND s.starts_on <= :today AND s.ends_on >= :today ORDER BY t.rank_weight DESC, s.starts_on LIMIT 1',
            ['venue' => $venueId, 'today' => TermDay::sql($now)],
        );

        return false === $row ? null : self::term($row);
    }

    /**
     * The last day of the venue's latest term, or null when it never had one.
     */
    public function paidUntil(string $venueId): ?\DateTimeImmutable
    {
        if (!Uuid::isValid($venueId)) {
            return null;
        }
        $day = $this->connection->fetchOne('SELECT max(ends_on) FROM subscriptions WHERE venue_id = :venue', ['venue' => $venueId]);

        return \is_string($day) ? new \DateTimeImmutable($day) : null;
    }

    /**
     * The venues the nightly job has to refresh: an active term ended before today, or `venues.tier_id` is not the
     * tier the terms give today (Free without a term). Empty on a second run.
     *
     * @return list<int> `venues.number`, ascending
     */
    public function venuesToRefresh(\DateTimeImmutable $now): array
    {
        /** @var list<int|string> $numbers */
        $numbers = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT v.number
                FROM venues v
                LEFT JOIN LATERAL (
                    SELECT s.tier_id
                    FROM subscriptions s
                    JOIN tiers st ON st.id = s.tier_id
                    WHERE s.venue_id = v.id AND s.starts_on <= :today AND s.ends_on >= :today
                    ORDER BY st.rank_weight DESC, s.starts_on
                    LIMIT 1
                ) term ON TRUE
                WHERE v.tier_id <> COALESCE(term.tier_id, :free)
                   OR EXISTS (SELECT 1 FROM subscriptions e WHERE e.venue_id = v.id AND e.status = :active AND e.ends_on < :today)
                ORDER BY v.number
                SQL,
            ['today' => TermDay::sql($now), 'free' => KnownTiers::FREE_ID, 'active' => SubscriptionStatus::Active->value],
        );

        return array_map(intval(...), $numbers);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function term(array $row): SubscriptionTerm
    {
        \assert(\is_string($row['starts_on']) && \is_string($row['ends_on']) && \is_string($row['paid_until']));

        return new SubscriptionTerm(
            (string) $row['id'],
            (string) $row['venue_id'],
            (int) $row['venue_number'],
            (string) $row['venue_name'],
            (string) $row['tier_code'],
            (string) $row['tier_name'],
            new \DateTimeImmutable($row['starts_on']),
            new \DateTimeImmutable($row['ends_on']),
            \is_string($row['cancel_requested_at']) ? new \DateTimeImmutable($row['cancel_requested_at']) : null,
            new \DateTimeImmutable($row['paid_until']),
        );
    }
}
