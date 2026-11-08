<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

use App\Portal\ValueObject\PortalVenueAccessStatus;
use App\Venues\ValueObject\SubscriptionStatus;
use App\Venues\ValueObject\TermDay;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Which reminder mails are due (G9): a term that is active, not cancelled, ends within 30 days and has no later term
 * paid yet gets the 30-day reminder while 8 to 30 days are left and the 7-day one in its last 7 days, each once
 * (`portal_subscription_reminders`). A term paid with less than 8 days left gets only the 7-day one.
 */
final class SubscriptionReminderQuery
{
    private const string DUE = <<<'SQL'
        FROM subscriptions s
        JOIN venues v ON v.id = s.venue_id
        JOIN tiers t ON t.id = s.tier_id
        CROSS JOIN LATERAL (SELECT CASE WHEN s.ends_on - CAST(:today AS date) <= 7 THEN 7 ELSE 30 END AS days_before) k
        WHERE s.status = :active AND s.cancel_requested_at IS NULL
          AND s.ends_on >= CAST(:today AS date) AND s.ends_on <= CAST(:today AS date) + 30
          AND NOT EXISTS (SELECT 1 FROM subscriptions n WHERE n.venue_id = s.venue_id AND n.starts_on > s.starts_on)
          AND NOT EXISTS (SELECT 1 FROM portal_subscription_reminders r WHERE r.subscription_id = s.id AND r.days_before = k.days_before)
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<array{subscriptionId: string, daysBefore: int}> by end date, then id
     */
    public function due(\DateTimeImmutable $now): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.id, k.days_before '.self::DUE.' ORDER BY s.ends_on, s.id',
            ['today' => TermDay::sql($now), 'active' => SubscriptionStatus::Active->value],
        );

        return array_map(static fn (array $row): array => ['subscriptionId' => (string) $row['id'], 'daysBefore' => (int) $row['days_before']], $rows);
    }

    /**
     * What the mail needs, or null when that reminder is no longer due (sent, cancelled, renewed, ended).
     */
    public function reminder(string $subscriptionId, int $daysBefore, \DateTimeImmutable $now): ?SubscriptionReminderRow
    {
        if (!Uuid::isValid($subscriptionId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT s.id, s.venue_id, v.number, v.name AS venue_name, v.inquiry_email, t.code AS tier_code, t.name AS tier_name,
                    t.price_rappen, t.vat_line, s.ends_on, s.amount_rappen = 0 AS free_of_charge '.self::DUE.' AND s.id = :id AND k.days_before = :days',
            ['today' => TermDay::sql($now), 'active' => SubscriptionStatus::Active->value, 'id' => $subscriptionId, 'days' => $daysBefore],
        );
        if (false === $row) {
            return null;
        }
        \assert(\is_string($row['ends_on']));
        $managers = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT u.email, u.first_name
                FROM portal_venue_access a
                JOIN portal_users u ON u.id = a.portal_user_id
                WHERE a.venue_id = :venue AND a.status = :active AND u.is_active
                ORDER BY u.email
                SQL,
            ['venue' => $row['venue_id'], 'active' => PortalVenueAccessStatus::Active->value],
        );
        $recipients = array_map(static fn (array $manager): array => ['email' => (string) $manager['email'], 'name' => (string) $manager['first_name']], $managers);
        if ([] === $recipients && \is_string($row['inquiry_email']) && '' !== $row['inquiry_email']) {
            $recipients = [['email' => $row['inquiry_email'], 'name' => null]];
        }

        return new SubscriptionReminderRow(
            (string) $row['id'],
            (string) $row['venue_name'],
            (string) $row['tier_code'],
            (string) $row['tier_name'],
            (int) $row['price_rappen'],
            true === $row['vat_line'],
            new \DateTimeImmutable($row['ends_on']),
            $recipients,
            true === $row['free_of_charge'],
        );
    }
}
