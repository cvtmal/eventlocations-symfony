<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Venues\ValueObject\VenuePlace;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The venues the "Eintrag zuordnen" combobox offers: by name (#6's folding, `idx_venues_search_key`) or by
 * number, published or not, with the company each already belongs to. One statement.
 */
final class VenueLookupQuery
{
    /** `venues.number` is an INTEGER. */
    private const int MAX_NUMBER_DIGITS = 9;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<VenueOption>
     */
    public function search(string $q, int $limit): array
    {
        $q = trim($q);
        if ('' === $q) {
            return [];
        }

        $parameters = ['limit' => $limit];
        $types = ['limit' => ParameterType::INTEGER];
        if (1 === preg_match('/\A\d+\z/', $q)) {
            if (\strlen($q) > self::MAX_NUMBER_DIGITS) {
                return [];
            }
            $condition = 'v.number = :number';
            $parameters['number'] = (int) $q;
            $types['number'] = ParameterType::INTEGER;
        } else {
            $condition = "(geo_fold_de(:q) <> '' AND (geo_search_key(v.name) LIKE '%' || geo_fold_de(:q) || '%' OR geo_search_key(v.name) LIKE '%' || geo_fold(:q) || '%'))";
            $parameters['q'] = $q;
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT v.id, v.number, v.name, tw.name AS town_name, v.canton_code, co.name AS company_name
                FROM venues v
                LEFT JOIN towns tw ON tw.id = v.town_id
                LEFT JOIN crm_companies co ON co.venue_id = v.id
                WHERE {$condition}
                ORDER BY lower(v.name), v.number
                LIMIT :limit
                SQL,
            $parameters,
            $types,
        );

        return array_map(static fn (array $row): VenueOption => new VenueOption(
            (string) $row['id'],
            (int) $row['number'],
            (string) $row['name'],
            VenuePlace::of(null === $row['town_name'] ? null : (string) $row['town_name'], null === $row['canton_code'] ? null : (string) $row['canton_code'], null),
            null === $row['company_name'] ? null : (string) $row['company_name'],
        ), $rows);
    }
}
