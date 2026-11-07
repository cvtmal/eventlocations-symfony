<?php

declare(strict_types=1);

namespace App\Venues;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The one rule for what the public may see of a stored photo (G1): a photo is public only with a
 * live grant (not revoked) that allows the listing and resized copies. Every public read model
 * puts `sql()` in the WHERE of its photo query, so a photo without a grant is in no card, gallery,
 * hero, similar-venues list, Merkliste, sitemap image, og:image or JSON-LD.
 *
 * A Google Places live photo (D12) is not a `venue_photos` row, so this rule never sees it: it
 * rests on Google's terms, not on a grant. Previews that only staff and the venue's own managers
 * see (`VenueDetailQuery::detail(includeUnpublished: true)`) show every stored photo, so a
 * manager sees what the entry will look like once the declaration is given; a preview anybody can
 * open (G5's signed link) uses `filter()` or `sql()` like the public pages.
 *
 * Revoking removes the photo itself (`RemoveVenuePhoto` closes the grant first), so a photo that
 * is not public is one that has not been granted yet.
 */
final class PublicPhotoPolicy
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * The predicate for a query over `venue_photos <alias>`; the alias is the caller's own, a
     * plain SQL identifier. It uses `rg` for its own table, so a caller must not: the predicate
     * would compare the grant with itself and hide every photo without a word.
     */
    public static function sql(string $photoAlias): string
    {
        // The D modifier: without it `$` also matches before a final newline.
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/D', $photoAlias)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a plain SQL alias.', $photoAlias));
        }
        if ('rg' === $photoAlias) {
            throw new \InvalidArgumentException('"rg" is the alias of the grants table inside the predicate; name the photo table otherwise.');
        }

        return \sprintf(
            'EXISTS (SELECT 1 FROM media_rights_grants rg WHERE rg.venue_photo_id = %s.id AND rg.revoked_at IS NULL AND rg.scope_listing AND rg.scope_resized_copies)',
            $photoAlias,
        );
    }

    /**
     * @param list<string> $photoIds
     *
     * @return list<string> the ids the public may see, in the order given
     */
    public function filter(array $photoIds): array
    {
        // The column is a UUID and Postgres refuses anything else with SQLSTATE 22P02, a 500 for a crafted id.
        // An id that is no UUID names no photo, so it is not public, and the database never sees it.
        $candidates = array_values(array_filter($photoIds, Uuid::isValid(...)));
        if ([] === $candidates) {
            return [];
        }

        $public = $this->connection->fetchFirstColumn(
            \sprintf('SELECT vp.id FROM venue_photos vp WHERE vp.id IN (:ids) AND %s', self::sql('vp')),
            ['ids' => $candidates],
            ['ids' => ArrayParameterType::STRING],
        );
        $public = array_flip(array_map(strval(...), $public));

        return array_values(array_filter($candidates, static fn (string $id): bool => isset($public[$id])));
    }
}
