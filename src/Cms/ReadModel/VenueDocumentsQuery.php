<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

use App\Media\MediaPaths;
use App\Venues\RowTokens;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The documents page (#25, CMS-7): a venue's documents by position, or one of them, with titles in
 * every locale and the row token. `forVenue()` runs two queries, `document()` one.
 */
final class VenueDocumentsQuery
{
    private const string COLUMNS = <<<'SQL'
        d.id, d.position, d.size_bytes,
        (SELECT COALESCE(jsonb_object_agg(t.locale, t.title), '{}')
         FROM venue_document_translations t WHERE t.venue_document_id = d.id) AS titles
        SQL;

    public function __construct(
        private readonly Connection $connection,
        private readonly VenueIdQuery $venues,
    ) {
    }

    /**
     * @return ?list<ManagedDocument> null for an unknown venue
     */
    public function forVenue(int $number): ?array
    {
        $venueId = $this->venues->byNumber($number);
        if (null === $venueId) {
            return null;
        }

        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT %s, %s::text AS token FROM venue_documents d WHERE d.venue_id = :id ORDER BY d.position', self::COLUMNS, RowTokens::sql('d.updated_at')),
            ['id' => $venueId],
        );

        return array_map(static fn (array $row): ManagedDocument => self::view($number, $row), $rows);
    }

    /**
     * A document of this venue; null when the venue, the id or the pair is unknown (D21).
     */
    public function document(int $number, string $documentId): ?ManagedDocument
    {
        if ($number < 1 || $number > VenueHeaderQuery::MAX_NUMBER || !Uuid::isValid($documentId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            \sprintf(
                'SELECT %s, %s::text AS token FROM venue_documents d JOIN venues v ON v.id = d.venue_id WHERE v.number = :number AND d.id = :document',
                self::COLUMNS,
                RowTokens::sql('d.updated_at'),
            ),
            ['number' => $number, 'document' => $documentId],
        );

        return false === $row ? null : self::view($number, $row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function view(int $number, array $row): ManagedDocument
    {
        $titles = [];
        $decoded = json_decode((string) $row['titles'], true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));
        foreach ($decoded as $locale => $title) {
            $titles[(string) $locale] = (string) $title;
        }
        ksort($titles);
        $id = (string) $row['id'];

        return new ManagedDocument(
            id: $id,
            position: (int) $row['position'],
            titles: $titles,
            sizeBytes: (int) $row['size_bytes'],
            url: MediaPaths::documentUrl($number, $id),
            token: (string) $row['token'],
        );
    }
}
