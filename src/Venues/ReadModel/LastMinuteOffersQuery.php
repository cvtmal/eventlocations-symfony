<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\TranslationJoin;
use App\Venues\EntitlementResolver;
use App\Venues\LastMinuteOfferPolicy;
use App\Venues\ValueObject\TermDay;
use App\Venues\ValueObject\TermGroup;
use Doctrine\DBAL\Connection;

/**
 * The live offers «Kurzfristig frei» (G14) of one venue for its `/konto` tab: every offer whose last day is today or
 * later, soonest first, each marked hidden when the venue's plan does not show offers (a lapsed term). An offer past
 * its last day is gone here as on every public page, before the nightly job deletes it. Two queries.
 */
final class LastMinuteOffersQuery
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TranslationJoin $translationJoin,
        private readonly EnabledLocale $locales,
        private readonly EntitlementResolver $entitlements,
    ) {
    }

    /**
     * @return list<LastMinuteOfferView>
     */
    public function forVenue(string $venueId, \DateTimeImmutable $now): array
    {
        $translation = $this->translationJoin->localized('room_translations', 'room_id', 'o.room_id', 'rt');
        $live = LastMinuteOfferPolicy::dateSql('o', 'today');

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT o.id, o.starts_on, o.ends_on, o.event_types, o.price_text, o.min_guests, o.terms, o.room_id, rt.name AS room_name
                FROM last_minute_offers o
                {$translation}
                WHERE o.venue_id = :venue AND {$live}
                ORDER BY o.starts_on, o.ends_on, o.id
                SQL,
            ['venue' => $venueId, 'today' => TermDay::sql($now)] + $this->translationJoin->parameters($this->locales->default()),
        );
        if ([] === $rows) {
            return [];
        }
        $visible = $this->entitlements->forVenue($venueId, $now)->lastMinuteAllowed;

        return array_map(static function (array $row) use ($visible): LastMinuteOfferView {
            \assert(null === $row['room_name'] || \is_string($row['room_name']));

            return LastMinuteOfferView::fromRow($row, $row['room_name'], $visible);
        }, $rows);
    }

    /**
     * What the `/konto` form offers: the rooms the venue's page shows (the first `roomsMax` named ones, F12) and the
     * venue's «Geeignet für» codes in vocabulary order. Three queries.
     *
     * @return array{rooms: array<string, string>, eventTypes: list<string>} rooms: id → name
     */
    public function choices(string $venueId, \DateTimeImmutable $now): array
    {
        $translation = $this->translationJoin->localized('room_translations', 'room_id', 'r.id', 'rt');
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT r.id, rt.name
                FROM rooms r
                {$translation}
                WHERE r.venue_id = :venue AND rt.name IS NOT NULL
                ORDER BY r.position
                LIMIT :max
                SQL,
            ['venue' => $venueId, 'max' => $this->entitlements->forVenue($venueId, $now)->roomsMax] + $this->translationJoin->parameters($this->locales->default()),
        );
        $rooms = [];
        foreach ($rows as $row) {
            \assert(\is_string($row['id']) && \is_string($row['name']));
            $rooms[$row['id']] = $row['name'];
        }

        /** @var list<string> $eventTypes */
        $eventTypes = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT vt.term_code
                FROM venue_terms vt
                JOIN taxonomy_terms tt ON tt.term_group = vt.term_group AND tt.code = vt.term_code
                WHERE vt.venue_id = :venue AND vt.term_group = :group
                ORDER BY tt.position
                SQL,
            ['venue' => $venueId, 'group' => TermGroup::SuitableFor->value],
        );

        return ['rooms' => $rooms, 'eventTypes' => $eventTypes];
    }

    /**
     * How many offers the nightly job would delete (`--dry-run`): their last day lies before the Zurich day of `$now`.
     */
    public function expiredCount(\DateTimeImmutable $now): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM last_minute_offers WHERE ends_on < :today', ['today' => TermDay::sql($now)]);
    }
}
