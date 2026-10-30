<?php

declare(strict_types=1);

namespace App\Geo\ReadModel;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * "Towns within N km of a town", on cube + earthdistance. earth_box() lets the GiST index
 * idx_towns_earth prefilter; earth_distance() then cuts the box to the circle.
 */
final class TownLocator
{
    private const MAX_KM = 200.0;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<TownDistance> nearest first, the centre town included at 0 km; [] for an unknown town
     */
    public function withinKm(string $townId, float $km): array
    {
        if ($km <= 0.0 || $km > self::MAX_KM) {
            throw new \InvalidArgumentException(\sprintf('The radius must be greater than 0 and at most %d km, got %s.', (int) self::MAX_KM, $km));
        }
        if (!Uuid::isValid($townId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT t.id, t.name, t.canton_code, t.zip,
                    earth_distance(ll_to_earth(c.lat, c.lng), ll_to_earth(t.lat, t.lng)) / 1000 AS distance_km
                FROM towns c
                JOIN towns t ON earth_box(ll_to_earth(c.lat, c.lng), :metres) @> ll_to_earth(t.lat, t.lng)
                WHERE c.id = :id
                  AND earth_distance(ll_to_earth(c.lat, c.lng), ll_to_earth(t.lat, t.lng)) <= :metres
                ORDER BY distance_km, t.name
                SQL,
            ['id' => $townId, 'metres' => $km * 1000],
        );

        return array_map(static function (array $row): TownDistance {
            \assert(\is_string($row['id']));
            \assert(\is_string($row['name']));
            \assert(\is_string($row['canton_code']));
            \assert(\is_string($row['zip']));
            \assert(is_numeric($row['distance_km']));

            return new TownDistance($row['id'], $row['name'], $row['canton_code'], $row['zip'], (float) $row['distance_km']);
        }, $rows);
    }
}
