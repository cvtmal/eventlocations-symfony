<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * The tiers with the number of venues on each (F12), for the staff screen "Tarife": best first, as the listings rank
 * them, then by price and code so the order never flips between two equal tiers.
 */
final class TierDefinitionsQuery
{
    private const string SELECT = <<<'SQL'
        SELECT t.code, t.name, t.price_rappen, t.vat_line, t.rank_weight, t.photos_max, t.rooms_max, t.videos_max, t.documents_max,
               t.contact_visible, t.last_minute_allowed, t.detailed_report, t.event_types_max,
               (SELECT count(*) FROM venues v WHERE v.tier_id = t.id) AS venue_count
        FROM tiers t
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<TierDefinition>
     */
    public function all(): array
    {
        $rows = $this->connection->fetchAllAssociative(self::SELECT.' ORDER BY t.rank_weight DESC, t.price_rappen DESC, t.code');

        return array_map(self::definition(...), $rows);
    }

    public function byCode(string $code): ?TierDefinition
    {
        $row = $this->connection->fetchAssociative(self::SELECT.' WHERE t.code = :code', ['code' => $code]);

        return false === $row ? null : self::definition($row);
    }

    /**
     * The code of the tier that has this name, as `uk_tiers_name` sees it (an exact match), or null. For the staff
     * form's check that a rename does not take another tier's name. The text must be valid UTF-8 without a NUL: the
     * database refuses anything else with an error, not an answer.
     */
    public function codeByName(string $name): ?string
    {
        $code = $this->connection->fetchOne('SELECT code FROM tiers WHERE name = :name', ['name' => $name]);

        return false === $code ? null : (string) $code;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function definition(array $row): TierDefinition
    {
        \assert(\is_string($row['code']) && \is_string($row['name']));
        \assert(\is_int($row['price_rappen']) && \is_int($row['rank_weight']));
        \assert(\is_int($row['photos_max']) && \is_int($row['rooms_max']) && \is_int($row['videos_max']) && \is_int($row['documents_max']));
        \assert(null === $row['event_types_max'] || \is_int($row['event_types_max']));

        return new TierDefinition(
            $row['code'],
            $row['name'],
            $row['price_rappen'],
            (bool) $row['vat_line'],
            $row['rank_weight'],
            $row['photos_max'],
            $row['rooms_max'],
            $row['videos_max'],
            $row['documents_max'],
            (bool) $row['contact_visible'],
            (bool) $row['last_minute_allowed'],
            (bool) $row['detailed_report'],
            $row['event_types_max'],
            (int) $row['venue_count'],
        );
    }
}
