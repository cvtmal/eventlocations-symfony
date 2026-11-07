<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

use App\Shared\Translation\EnabledLocale;
use App\Venues\ValueObject\VenuePlace;
use Doctrine\DBAL\Connection;

/**
 * The locations that wait for staff, oldest first. Only unpublished ones: a publish in the CMS empties the row out of
 * the queue with no code of its own.
 */
final readonly class PendingSubmissionQuery
{
    private const string PENDING = <<<'SQL'
        SELECT v.id, v.number, v.name, v.canton_code, tw.name AS town_name, t.region, t.slug,
               u.first_name, u.last_name, u.email, s.submitted_at
        FROM portal_submissions s
        JOIN venues v ON v.id = s.venue_id
        LEFT JOIN towns tw ON tw.id = v.town_id
        LEFT JOIN venue_translations t ON t.venue_id = v.id AND t.locale = :locale
        LEFT JOIN portal_users u ON u.id = s.submitted_by
        WHERE s.status = 'submitted' AND v.status = 'unpublished'
        SQL;

    public function __construct(
        private Connection $connection,
        private EnabledLocale $locales,
    ) {
    }

    /**
     * @return list<PendingSubmissionRow>
     */
    public function oldest(): array
    {
        $rows = $this->connection->fetchAllAssociative(self::PENDING.' ORDER BY s.submitted_at, v.number', ['locale' => $this->locales->default()]);

        return array_map(self::row(...), $rows);
    }

    public function find(int $number): ?PendingSubmissionRow
    {
        $row = $this->connection->fetchAssociative(self::PENDING.' AND v.number = :number', ['locale' => $this->locales->default(), 'number' => $number]);

        return false === $row ? null : self::row($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function row(array $row): PendingSubmissionRow
    {
        return new PendingSubmissionRow(
            (string) $row['id'],
            (int) $row['number'],
            (string) $row['name'],
            VenuePlace::of(self::string($row['town_name']), self::string($row['canton_code']), self::string($row['region'])),
            self::string($row['first_name']),
            self::string($row['last_name']),
            self::string($row['email']),
            new \DateTimeImmutable((string) $row['submitted_at']),
            self::string($row['slug']),
        );
    }

    private static function string(mixed $value): ?string
    {
        return null === $value ? null : (string) $value;
    }
}
