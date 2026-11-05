<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\Exception\StaleEdit;

/**
 * The stale check of one child row (#25, PF2, D16): a photo's alt texts, a room, a document
 * title. VenueEditing::open() compares the venue's token, but close() moves
 * `venues.updated_at` on every write, so two requests of one island (an upload and an alt
 * save) would stale each other. A row token instead changes only when that row (or a child
 * it touches) was written.
 *
 * The token is the row's `updated_at` in Unix seconds, read from the database when the form
 * or the island's state was rendered (sql()); the columns are TIMESTAMP(0), so the value is
 * whole seconds. Handlers lock the venue (VenueEditing::lock()), load the row, and call
 * assertFresh() before any mutation, so the entity still holds the database value.
 */
final class RowTokens
{
    /**
     * The token as a read model selects it, e.g. `RowTokens::sql('p.updated_at').' AS loaded_at'`.
     */
    public static function sql(string $column): string
    {
        return \sprintf('EXTRACT(EPOCH FROM %s)::bigint', $column);
    }

    /**
     * @throws StaleEdit when the row was written after the token was read
     */
    public static function assertFresh(\DateTimeImmutable $updatedAt, string $loadedAt, int $venueNumber): void
    {
        if ($updatedAt->format('U') !== $loadedAt) {
            throw StaleEdit::forVenue($venueNumber);
        }
    }
}
