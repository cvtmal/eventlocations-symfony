<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Shared\Translation\TranslationJoin;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\GrantorType;
use Doctrine\DBAL\Connection;

/**
 * The rights state of a venue's stored photos (G1): who may see which photo is `PublicPhotoPolicy`'s
 * rule; this answers the other question, "which photos has somebody granted, and on what evidence",
 * for the "Bildrechte" card of the back office and of `/konto`. It lists every stored photo, public
 * or not, and reads the grants table next to it, so it does not use the policy's predicate: it is the
 * one reader that must see the photos the public cannot.
 */
final class PhotoRightsQuery
{
    /** `venues.number` is an INTEGER: a larger value is no venue, not a database error. */
    private const int MAX_NUMBER = 2147483647;

    public function __construct(
        private readonly Connection $connection,
        private readonly TranslationJoin $translations,
    ) {
    }

    /**
     * @param ?string $managerId the portal user the page is for: the view's `declaredAt` is when that manager declared.
     *                           The staff card passes none, has no declaration of its own, and gets null
     *
     * @return ?PhotoRightsView null for an unknown venue
     */
    public function forVenue(int $number, string $locale, ?string $managerId = null): ?PhotoRightsView
    {
        $venueId = $this->venueId($number);
        if (null === $venueId) {
            return null;
        }

        $roomName = $this->translations->localized('room_translations', 'room_id', 'r.id', 'rt');
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT p.id, p.position, NULLIF(rt.name, '') AS room_name,
                       g.evidence, g.grantor_type, g.created_at AS granted_at, g.scope_derivatives, g.policy_version,
                       EXISTS (SELECT 1 FROM moderation_case_photos mcp JOIN moderation_cases mc ON mc.id = mcp.case_id WHERE mcp.venue_photo_id = p.id AND mc.status = 'open') AS held
                FROM venue_photos p
                LEFT JOIN rooms r ON r.id = p.room_id
                {$roomName}
                LEFT JOIN media_rights_grants g ON g.venue_photo_id = p.id AND g.revoked_at IS NULL
                WHERE p.venue_id = :venue
                ORDER BY p.room_id IS NOT NULL, r.position, p.position
                SQL,
            ['venue' => $venueId] + $this->translations->parameters($locale),
        );

        return new PhotoRightsView(
            array_map(self::row(...), $rows),
            null === $managerId ? null : $this->declarationOf($venueId, $managerId)?->at,
        );
    }

    /**
     * The latest declaration this manager gave for the venue, or null when there is none. A declaration is
     * personal: staff add and swap the managers of a venue, and what one of them accepted does not cover
     * another. Two small queries without the photo list, for the upload of a manager, which needs to know
     * whether the declaration exists and under which text.
     */
    public function declaration(int $number, string $managerId): ?ManagerDeclaration
    {
        $venueId = $this->venueId($number);

        return null === $venueId ? null : $this->declarationOf($venueId, $managerId);
    }

    private function venueId(int $number): ?string
    {
        if ($number < 1 || $number > self::MAX_NUMBER) {
            return null;
        }

        $id = $this->connection->fetchOne('SELECT id FROM venues WHERE number = :number', ['number' => $number]);

        return false === $id ? null : (string) $id;
    }

    /**
     * A grant counts live or revoked, and whatever became of its photo: the manager said it once, and a
     * removed photo keeps its grant row as evidence. The grants of one declaration are written in one
     * transaction, so they agree on the version; `id` (a UUID v7, so in time order) settles a tie between
     * two declarations of the same second.
     */
    private function declarationOf(string $venueId, string $managerId): ?ManagerDeclaration
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT created_at, policy_version
                FROM media_rights_grants
                WHERE venue_id = :venue AND grantor_type = 'manager' AND grantor_ref = :manager AND evidence = 'declaration'
                ORDER BY created_at DESC, id DESC
                LIMIT 1
                SQL,
            ['venue' => $venueId, 'manager' => $managerId],
        );

        return false === $row ? null : new ManagerDeclaration(new \DateTimeImmutable((string) $row['created_at']), (string) $row['policy_version']);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function row(array $row): PhotoRightsRow
    {
        $granted = null !== $row['evidence'];

        return new PhotoRightsRow(
            photoId: (string) $row['id'],
            position: (int) $row['position'],
            roomName: null === $row['room_name'] ? null : (string) $row['room_name'],
            granted: $granted,
            evidence: $granted ? GrantEvidence::from((string) $row['evidence']) : null,
            grantorType: $granted ? GrantorType::from((string) $row['grantor_type']) : null,
            grantedAt: $granted ? new \DateTimeImmutable((string) $row['granted_at']) : null,
            derivatives: $granted && (bool) $row['scope_derivatives'],
            policyVersion: $granted ? (string) $row['policy_version'] : null,
            held: true === $row['held'],
        );
    }
}
