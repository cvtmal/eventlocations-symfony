<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The preview links of G5 (`venue_previews`): one by id for the public preview page, all of an
 * entry for the staff tab.
 */
final class VenuePreviewQuery
{
    private const string COLUMNS = 'p.id, p.venue_id, v.number, p.campaign_code, p.created_at, p.expires_at, p.revoked_at';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function find(string $id): ?VenuePreviewLink
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM venue_previews p JOIN venues v ON v.id = p.venue_id WHERE p.id = :id',
            ['id' => $id],
        );

        return false === $row ? null : self::link($row);
    }

    /**
     * The entry's links, newest first.
     *
     * @return list<VenuePreviewLink>
     */
    public function forVenue(int $number): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM venue_previews p JOIN venues v ON v.id = p.venue_id WHERE v.number = :number ORDER BY p.created_at DESC, p.id DESC',
            ['number' => $number],
        );

        return array_map(self::link(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function link(array $row): VenuePreviewLink
    {
        \assert(\is_string($row['id']) && \is_string($row['venue_id']) && \is_int($row['number']) && \is_string($row['campaign_code']));
        \assert(\is_string($row['created_at']) && \is_string($row['expires_at']) && (null === $row['revoked_at'] || \is_string($row['revoked_at'])));

        return new VenuePreviewLink(
            $row['id'],
            $row['venue_id'],
            $row['number'],
            $row['campaign_code'],
            new \DateTimeImmutable($row['created_at']),
            new \DateTimeImmutable($row['expires_at']),
            null === $row['revoked_at'] ? null : new \DateTimeImmutable($row['revoked_at']),
        );
    }
}
