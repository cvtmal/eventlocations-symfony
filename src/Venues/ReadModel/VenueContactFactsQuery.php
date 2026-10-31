<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * What a venue says about who runs it, for the CRM's bootstrap (D19). Any status counts, so this is not
 * `VenueDetailQuery::phone()`, which reads published venues only. Null for an unknown id, a non-UUID
 * included.
 */
final class VenueContactFactsQuery
{
    private const string SQL = <<<'SQL'
        SELECT v.id, v.number, v.name, v.website, v.phone, v.inquiry_email, v.contact_person, v.street, v.zip,
               tw.name AS town_name
        FROM venues v
        LEFT JOIN towns tw ON tw.id = v.town_id
        WHERE v.id = :id
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function find(string $venueId): ?VenueContactFacts
    {
        if (!Uuid::isValid($venueId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(self::SQL, ['id' => $venueId]);
        if (false === $row) {
            return null;
        }

        return new VenueContactFacts(
            (string) $row['id'],
            (int) $row['number'],
            (string) $row['name'],
            self::text($row['website']),
            self::text($row['phone']),
            self::text($row['inquiry_email']),
            self::text($row['contact_person']),
            self::text($row['street']),
            self::text($row['zip']),
            self::text($row['town_name']),
        );
    }

    private static function text(mixed $value): ?string
    {
        return null === $value ? null : (string) $value;
    }
}
