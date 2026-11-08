<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * The photo candidates of a venue's latest website import (G3) that wait for a decision (G6). SQL on purpose: the
 * import's entities stay away from everything that serves a page (`PrivateImportDataTest`); only the claimant's
 * page behind `/konto` reads this, and only http and https URLs come back.
 */
final class PhotoCandidateQuery
{
    public const int LIMIT = 60;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<PhotoCandidateRow>
     */
    public function open(int $venueNumber): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT c.id, c.image_url, c.page_url, c.alt, c.width, c.height
                FROM photo_candidates c
                JOIN source_records s ON s.id = c.source_record_id
                JOIN venues v ON v.id = s.venue_id
                WHERE v.number = :number AND s.status = 'fetched' AND c.status = 'candidate'
                  AND c.image_url ~* '^https?://'
                ORDER BY s.fetched_at DESC, c.position, c.id
                LIMIT :limit
                SQL,
            ['number' => $venueNumber, 'limit' => self::LIMIT],
        );

        return array_map(static fn (array $row): PhotoCandidateRow => new PhotoCandidateRow(
            (string) $row['id'],
            (string) $row['image_url'],
            (string) $row['page_url'],
            null === $row['alt'] ? null : (string) $row['alt'],
            null === $row['width'] ? null : (int) $row['width'],
            null === $row['height'] ? null : (int) $row['height'],
        ), $rows);
    }
}
