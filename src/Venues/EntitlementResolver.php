<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\Exception\TierNotFound;
use App\Venues\ValueObject\Entitlements;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\TermDay;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The one answer to "which entitlements apply to this venue at `$now`" (F12). Every consumer asks here; nothing
 * copies a limit or a flag into a venue row, a card or a template.
 *
 * The answer is the paid term (G9): the tier of the `subscriptions` row that covers the Zurich day of `$now`
 * (`TermDay`), and Free without one, so a lapsed term falls back to Free at local midnight and the venue stays online.
 * `venues.tier_id` is not read: it is the tier the listings order by, kept in step by every term write and by the
 * nightly `app:subscriptions:expire`. Callers pass the instant (a `ClockInterface`), the resolver has no clock of
 * its own. Should two terms ever cover one day, the higher-ranked tier wins.
 *
 * Reads only through DBAL, so read models may use it. Use forVenues() for a list: one query for all venues.
 */
final class EntitlementResolver
{
    /**
     * The `tiers` columns `Entitlements::fromRow()` reads.
     */
    private const COLUMNS = <<<'SQL'
        t.code AS tier_code, t.name AS tier_name, t.rank_weight, t.photos_max, t.rooms_max, t.videos_max,
            t.documents_max, t.contact_visible, t.last_minute_allowed, t.detailed_report, t.event_types_max
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param string $venueId `venues.id`
     *
     * @throws TierNotFound when the venue does not exist and the Free row is missing too
     */
    public function forVenue(string $venueId, \DateTimeImmutable $now): Entitlements
    {
        return $this->forVenues([$venueId], $now)[$venueId];
    }

    /**
     * @param list<string> $venueIds `venues.id`
     *
     * @return array<string, Entitlements> one entry per id asked for; an id that is no venue gets Free's
     *
     * @throws TierNotFound when an id is no venue and the Free row is missing too
     */
    public function forVenues(array $venueIds, \DateTimeImmutable $now): array
    {
        $venueIds = array_values(array_unique($venueIds));
        if ([] === $venueIds) {
            return [];
        }

        $columns = self::COLUMNS;
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT v.id AS venue_id, {$columns}
                FROM venues v
                LEFT JOIN LATERAL (
                    SELECT s.tier_id
                    FROM subscriptions s
                    JOIN tiers st ON st.id = s.tier_id
                    WHERE s.venue_id = v.id AND s.starts_on <= :today AND s.ends_on >= :today
                    ORDER BY st.rank_weight DESC, s.starts_on
                    LIMIT 1
                ) term ON TRUE
                JOIN tiers t ON t.id = COALESCE(term.tier_id, :free)
                WHERE v.id IN (:ids)
                SQL,
            ['ids' => $venueIds, 'today' => TermDay::sql($now), 'free' => KnownTiers::FREE_ID],
            ['ids' => ArrayParameterType::STRING],
        );

        $found = [];
        foreach ($rows as $row) {
            \assert(\is_string($row['venue_id']));
            $found[$row['venue_id']] = Entitlements::fromRow($row);
        }

        $entitlements = [];
        foreach ($venueIds as $venueId) {
            $entitlements[$venueId] = $found[$venueId] ?? $this->forTier(KnownTiers::FREE);
        }

        return $entitlements;
    }

    /**
     * A tier's own entitlements, for what a venue would get on it: the plan pages, the CMS preview, the fallback.
     *
     * @throws TierNotFound
     */
    public function forTier(string $code): Entitlements
    {
        $columns = self::COLUMNS;
        $row = $this->connection->fetchAssociative("SELECT {$columns} FROM tiers t WHERE t.code = :code", ['code' => $code]);
        if (false === $row) {
            throw TierNotFound::forCode($code);
        }

        return Entitlements::fromRow($row);
    }
}
