<?php

declare(strict_types=1);

namespace App\Geo\ReadModel;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Resolves a `place` filter value (town UUID or 4-digit ZIP) to its town. A ZIP means the most
 * populous town of that ZIP, the same rule as the radius centre in Search's ListingSql.
 *
 * Memoized per value, misses included: the filter bar asks twice per request (chip and overlay).
 * Tagged kernel.reset by autoconfiguration, so a long-running worker forgets the memo.
 */
final class PlaceResolver implements ResetInterface
{
    /** @var array<string, ?ResolvedPlace> */
    private array $resolved = [];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function resolve(string $place): ?ResolvedPlace
    {
        if (\array_key_exists($place, $this->resolved)) {
            return $this->resolved[$place];
        }

        return $this->resolved[$place] = $this->load($place);
    }

    public function reset(): void
    {
        $this->resolved = [];
    }

    private function load(string $place): ?ResolvedPlace
    {
        if (1 === preg_match('/^\d{4}$/', $place)) {
            return $this->place(
                'SELECT t.name, tz.zip, t.lat, t.lng FROM town_zips tz JOIN towns t ON t.id = tz.town_id WHERE tz.zip = :place ORDER BY t.population DESC, t.name LIMIT 1',
                $place,
            );
        }

        if (!Uuid::isValid($place)) {
            return null;
        }

        return $this->place('SELECT name, zip, lat, lng FROM towns WHERE id = :place', $place);
    }

    private function place(string $sql, string $place): ?ResolvedPlace
    {
        $row = $this->connection->fetchAssociative($sql, ['place' => $place]);
        if (false === $row) {
            return null;
        }
        \assert(\is_string($row['name']));
        \assert(\is_string($row['zip']));
        \assert(\is_float($row['lat']));
        \assert(\is_float($row['lng']));

        return new ResolvedPlace($row['name'], $row['zip'], $row['lat'], $row['lng']);
    }
}
