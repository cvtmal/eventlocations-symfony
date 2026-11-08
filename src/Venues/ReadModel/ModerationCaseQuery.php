<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Media\ValueObject\ImageVariants;
use App\Media\ValueObject\Picture;
use App\Venues\ValueObject\ModerationDecision;
use App\Venues\ValueObject\ModerationKind;
use App\Venues\ValueObject\ModerationStatus;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The complaints of the public form for staff (G2): the queue (open cases oldest first, then the latest decided
 * ones) and one case with the photos it names. It reads the named photos
 * whether the public sees them or not: staff must see what a complaint hides. The mails read `find()` too.
 */
final class ModerationCaseQuery
{
    /** A count of the named photos that still exist, for an open case only: a decided case hides nothing. */
    private const string HELD = "CASE WHEN c.status = 'open' THEN (SELECT count(*) FROM moderation_case_photos mcp WHERE mcp.case_id = c.id AND mcp.venue_photo_id IS NOT NULL) ELSE 0 END";

    private const string ROW_COLUMNS = 'c.id, c.created_at, c.kind, c.status, c.decision, c.decided_at, c.venue_reference, c.complainant_name, v.number AS venue_number, v.name AS venue_name, '.self::HELD.' AS held_photos';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<ModerationCaseRow> every open case, the oldest first: it has waited longest
     */
    public function open(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::ROW_COLUMNS." FROM moderation_cases c LEFT JOIN venues v ON v.id = c.venue_id WHERE c.status = 'open' ORDER BY c.created_at, c.id",
        );

        return array_map(self::row(...), $rows);
    }

    /**
     * @return list<ModerationCaseRow> the latest decided cases, the newest decision first
     */
    public function decided(int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::ROW_COLUMNS." FROM moderation_cases c LEFT JOIN venues v ON v.id = c.venue_id WHERE c.status = 'decided' ORDER BY c.decided_at DESC, c.id DESC LIMIT :limit",
            ['limit' => max(0, $limit)],
        );

        return array_map(self::row(...), $rows);
    }

    /**
     * @return ?ModerationCaseView null for an unknown id, or one that is no UUID
     */
    public function find(string $id): ?ModerationCaseView
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $case = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT c.*, v.number AS venue_number, v.name AS venue_name, v.status = 'published' AS venue_published
                FROM moderation_cases c
                LEFT JOIN venues v ON v.id = c.venue_id
                WHERE c.id = :id
                SQL,
            ['id' => $id],
        );
        if (false === $case) {
            return null;
        }

        $photos = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT mcp.original_path, p.id, p.width, p.height, p.dominant_color, p.variants, pv.number
                FROM moderation_case_photos mcp
                LEFT JOIN venue_photos p ON p.id = mcp.venue_photo_id
                LEFT JOIN venues pv ON pv.id = p.venue_id
                WHERE mcp.case_id = :id
                ORDER BY mcp.created_at, mcp.id
                SQL,
            ['id' => $id],
        );

        return new ModerationCaseView(
            id: (string) $case['id'],
            kind: ModerationKind::from((string) $case['kind']),
            status: ModerationStatus::from((string) $case['status']),
            venueNumber: null === $case['venue_number'] ? null : (int) $case['venue_number'],
            venueName: null === $case['venue_name'] ? null : (string) $case['venue_name'],
            venuePublished: true === $case['venue_published'],
            venueReference: null === $case['venue_reference'] ? null : (string) $case['venue_reference'],
            complainantName: null === $case['complainant_name'] ? null : (string) $case['complainant_name'],
            complainantEmail: null === $case['complainant_email'] ? null : (string) $case['complainant_email'],
            message: null === $case['message'] ? null : (string) $case['message'],
            complainantErasedAt: null === $case['complainant_erased_at'] ? null : new \DateTimeImmutable((string) $case['complainant_erased_at']),
            goodFaithAt: new \DateTimeImmutable((string) $case['good_faith_at']),
            sourceUrl: null === $case['source_url'] ? null : (string) $case['source_url'],
            createdAt: new \DateTimeImmutable((string) $case['created_at']),
            decision: null === $case['decision'] ? null : ModerationDecision::from((string) $case['decision']),
            decisionNote: null === $case['decision_note'] ? null : (string) $case['decision_note'],
            decidedAt: null === $case['decided_at'] ? null : new \DateTimeImmutable((string) $case['decided_at']),
            decidedBy: null === $case['decided_by'] ? null : (string) $case['decided_by'],
            tookEntryOffline: true === $case['took_entry_offline'],
            photos: array_map(static fn (array $row): ModerationCasePhotoView => new ModerationCasePhotoView(
                null === $row['id'] ? null : new Picture((int) $row['number'], (string) $row['id'], (int) $row['width'], (int) $row['height'], (string) $row['dominant_color'], ImageVariants::fromJson((string) $row['variants']), ''),
                (string) $row['original_path'],
            ), $photos),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function row(array $row): ModerationCaseRow
    {
        return new ModerationCaseRow(
            id: (string) $row['id'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            kind: ModerationKind::from((string) $row['kind']),
            status: ModerationStatus::from((string) $row['status']),
            decision: null === $row['decision'] ? null : ModerationDecision::from((string) $row['decision']),
            decidedAt: null === $row['decided_at'] ? null : new \DateTimeImmutable((string) $row['decided_at']),
            venueNumber: null === $row['venue_number'] ? null : (int) $row['venue_number'],
            venueName: null === $row['venue_name'] ? null : (string) $row['venue_name'],
            venueReference: null === $row['venue_reference'] ? null : (string) $row['venue_reference'],
            complainantName: null === $row['complainant_name'] ? null : (string) $row['complainant_name'],
            heldPhotos: (int) $row['held_photos'],
        );
    }
}
