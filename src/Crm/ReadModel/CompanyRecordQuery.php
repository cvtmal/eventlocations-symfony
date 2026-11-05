<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\CompanyOrigin;
use App\Crm\ValueObject\CompanyStage;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenuePlace;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * A company record page in one statement (D20): the company, its owner's name, its Eintrag (#56) and
 * its contacts as JSON. Null for an unknown id, a non-UUID included, so no 22P02 reaches a 500.
 */
final class CompanyRecordQuery
{
    private const string SQL = <<<'SQL'
        SELECT c.id, c.name, c.legal_name, c.website, c.phone, c.email, c.address, c.stage, c.owner_id, c.notes,
               c.origin, c.created_at, u.display_name AS owner_name,
               v.id AS venue_id, v.number AS venue_number, v.name AS venue_name, v.tier AS venue_tier,
               tw.name AS venue_town, v.canton_code AS venue_canton,
               COALESCE((
                   SELECT json_agg(json_build_object('id', p.id, 'first_name', p.first_name, 'last_name', p.last_name,
                                                     'position', p.position, 'email', p.email, 'phone', p.phone)
                                   ORDER BY lower(p.last_name), lower(coalesce(p.first_name, '')), p.id)
                   FROM crm_contacts p
                   WHERE p.company_id = c.id
               ), '[]') AS contacts,
               EXISTS (
                   SELECT 1 FROM crm_deals d JOIN crm_deal_stages s ON s.code = d.stage_code
                   WHERE d.company_id = c.id AND s.is_won
               ) AS stage_derived
        FROM crm_companies c
        LEFT JOIN admin_users u ON u.id = c.owner_id
        LEFT JOIN venues v ON v.id = c.venue_id
        LEFT JOIN towns tw ON tw.id = v.town_id
        WHERE c.id = :id
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function find(string $id): ?CompanyRecord
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(self::SQL, ['id' => $id]);
        if (false === $row) {
            return null;
        }

        return new CompanyRecord(
            (string) $row['id'],
            (string) $row['name'],
            self::text($row['legal_name']),
            self::text($row['website']),
            self::text($row['phone']),
            self::text($row['email']),
            self::text($row['address']),
            CompanyStage::from((string) $row['stage']),
            self::text($row['owner_id']),
            self::text($row['owner_name']),
            self::text($row['notes']),
            CompanyOrigin::from((string) $row['origin']),
            new \DateTimeImmutable((string) $row['created_at']),
            null === $row['venue_id'] ? null : new CompanyRecordVenue(
                (string) $row['venue_id'],
                (int) $row['venue_number'],
                (string) $row['venue_name'],
                Tier::from((string) $row['venue_tier']),
                VenuePlace::of(self::text($row['venue_town']), self::text($row['venue_canton']), null),
            ),
            array_map(static fn (array $contact): CompanyRecordContact => new CompanyRecordContact(
                (string) $contact['id'],
                self::text($contact['first_name']),
                (string) $contact['last_name'],
                self::text($contact['position']),
                self::text($contact['email']),
                self::text($contact['phone']),
            ), self::list($row['contacts'])),
            (bool) $row['stage_derived'],
        );
    }

    /**
     * The name alone, for a combobox's label (the contact form's preset company). Null for an unknown id.
     */
    public function name(string $id): ?string
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $name = $this->connection->fetchOne('SELECT name FROM crm_companies WHERE id = :id', ['id' => $id]);

        return false === $name ? null : (string) $name;
    }

    private static function text(mixed $value): ?string
    {
        return null === $value ? null : (string) $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $json): array
    {
        $decoded = json_decode((string) $json, true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded) && array_is_list($decoded));

        /* @var list<array<string, mixed>> $decoded */
        return $decoded;
    }
}
