<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\LostReason;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenuePlace;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * A deal record page in one statement (D20): the deal, its company, owner and venue, its Location's contact rows
 * (read-only, #55) and what its forms offer as JSON. Null for an unknown id, a non-UUID included, so no 22P02 reaches a 500.
 */
final class DealRecordQuery
{
    private const string SQL = <<<'SQL'
        SELECT d.id, d.name, d.company_id, d.tier, d.amount_rappen, d.contract_start_on, d.contract_end_on,
               d.expected_close_on, d.stage_code, d.owner_id, d.lost_reason, d.closed_at, d.created_at,
               c.name AS company_name, u.display_name AS owner_name,
               d.venue_id, v.number AS venue_number, v.name AS venue_name, v.tier AS venue_tier,
               tw.name AS venue_town, v.canton_code AS venue_canton,
               COALESCE((
                   SELECT json_agg(json_build_object('id', p.id, 'first_name', p.first_name, 'last_name', p.last_name,
                                                     'position', p.position)
                                   ORDER BY lower(p.last_name), lower(coalesce(p.first_name, '')), p.id)
                   FROM crm_contacts p
                   WHERE p.company_id = d.company_id
               ), '[]') AS contacts,
               COALESCE((
                   SELECT json_agg(json_build_object('id', lv.id, 'label', lv.name) ORDER BY lower(lv.name), lv.number)
                   FROM crm_company_venues cv
                   JOIN venues lv ON lv.id = cv.venue_id
                   WHERE cv.company_id = d.company_id
               ), '[]') AS company_venues,
               s.is_won AND d.contract_end_on IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM crm_deals r WHERE r.renewed_from_id = d.id) AS renewable
        FROM crm_deals d
        JOIN crm_deal_stages s ON s.code = d.stage_code
        JOIN crm_companies c ON c.id = d.company_id
        LEFT JOIN admin_users u ON u.id = d.owner_id
        LEFT JOIN venues v ON v.id = d.venue_id
        LEFT JOIN towns tw ON tw.id = v.town_id
        WHERE d.id = :id
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function find(string $id): ?DealRecord
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(self::SQL, ['id' => $id]);
        if (false === $row) {
            return null;
        }

        return new DealRecord(
            (string) $row['id'],
            (string) $row['name'],
            (string) $row['company_id'],
            (string) $row['company_name'],
            null === $row['venue_id'] ? null : new CompanyRecordVenue(
                (string) $row['venue_id'],
                (int) $row['venue_number'],
                (string) $row['venue_name'],
                Tier::from((string) $row['venue_tier']),
                VenuePlace::of(self::text($row['venue_town']), self::text($row['venue_canton']), null),
            ),
            null === $row['tier'] ? null : Tier::from((string) $row['tier']),
            null === $row['amount_rappen'] ? null : (int) $row['amount_rappen'],
            self::text($row['contract_start_on']),
            self::text($row['contract_end_on']),
            self::text($row['expected_close_on']),
            (string) $row['stage_code'],
            self::text($row['owner_id']),
            self::text($row['owner_name']),
            null === $row['lost_reason'] ? null : LostReason::from((string) $row['lost_reason']),
            null === $row['closed_at'] ? null : new \DateTimeImmutable((string) $row['closed_at']),
            new \DateTimeImmutable((string) $row['created_at']),
            array_map(static fn (array $contact): CompanyRecordContact => new CompanyRecordContact(
                (string) $contact['id'],
                self::text($contact['first_name']),
                (string) $contact['last_name'],
                self::text($contact['position']),
            ), self::list($row['contacts'])),
            self::options($row['company_venues']),
            (bool) $row['renewable'],
        );
    }

    private static function text(mixed $value): ?string
    {
        return null === $value ? null : (string) $value;
    }

    /**
     * @return list<DealFormOption>
     */
    private static function options(mixed $json): array
    {
        return array_map(static fn (array $option): DealFormOption => new DealFormOption((string) $option['id'], (string) $option['label']), self::list($json));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $json): array
    {
        $items = json_decode((string) $json, true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($items) && array_is_list($items));

        /* @var list<array<string, mixed>> $items */
        return $items;
    }
}
