<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The venues the one-off place ID lookup (#53, `app:venues:find-google-places`) asks Google about: every venue
 * without a place ID, any status, by number. A venue staff gave an ID is never asked again. `havePlace()` lets
 * the apply command keep a venue that already has an ID without dispatching (#57), and its dry run say what a real
 * run would keep or not find; `placeOwners()` lets #57's finder see an ID already held by another venue.
 */
final class GooglePlaceLookupQuery
{
    private const string SQL = <<<'SQL'
        SELECT v.number, v.name, v.canton_code, t.region, v.status,
               CASE WHEN v.coordinates_from_town THEN NULL ELSE v.lat END AS lat,
               CASE WHEN v.coordinates_from_town THEN NULL ELSE v.lng END AS lng
        FROM venues v
        LEFT JOIN venue_translations t ON t.venue_id = v.id AND t.locale = 'de'
        WHERE v.google_place_id IS NULL
        ORDER BY v.number
        LIMIT :limit
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param int|null $limit at most this many venues, all without
     *
     * @return list<GooglePlaceLookupVenue>
     */
    public function withoutPlace(?int $limit = null): array
    {
        $rows = $this->connection->fetchAllAssociative(
            self::SQL,
            ['limit' => $limit],
            ['limit' => null === $limit ? ParameterType::NULL : ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): GooglePlaceLookupVenue => new GooglePlaceLookupVenue(
            (int) $row['number'],
            (string) $row['name'],
            (string) ($row['canton_code'] ?? ''),
            (string) ($row['region'] ?? ''),
            null === $row['lat'] ? null : (float) $row['lat'],
            null === $row['lng'] ? null : (float) $row['lng'],
            VenueStatus::Published->value === $row['status'],
        ), $rows);
    }

    /**
     * @param list<int> $numbers
     *
     * @return array<int, bool> venue number → whether it has a place ID; an unknown number is missing
     */
    public function havePlace(array $numbers): array
    {
        if ([] === $numbers) {
            return [];
        }
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT number, google_place_id IS NOT NULL FROM venues WHERE number IN (:numbers)',
            ['numbers' => $numbers],
            ['numbers' => ArrayParameterType::INTEGER],
        );

        $found = [];
        foreach ($rows as $number => $hasPlace) {
            $found[(int) $number] = (bool) $hasPlace;
        }

        return $found;
    }

    /**
     * The venues that already hold each place ID; the column has no unique constraint, so one ID may have several.
     *
     * @return array<string, list<int>> place ID → venue numbers, ascending
     */
    public function placeOwners(): array
    {
        $owners = [];
        foreach ($this->connection->fetchAllAssociative('SELECT google_place_id, number FROM venues WHERE google_place_id IS NOT NULL ORDER BY number') as $row) {
            $owners[(string) $row['google_place_id']][] = (int) $row['number'];
        }

        return $owners;
    }
}
