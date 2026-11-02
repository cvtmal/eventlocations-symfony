<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Shared\Translation\EnabledLocale;
use Doctrine\DBAL\Connection;

/**
 * What a venue still lacks to go online (CMS-3, D14): a summary in the default locale, a main building and at least
 * one gallery photo (`room_id IS NULL`; a room's photo is not on the card). The one rule, shared by `PublishVenue` and
 * the portal's wizard. An inquiry address is no part of it: that is a wizard form rule only (#45).
 */
final readonly class VenuePublishGaps
{
    public function __construct(
        private Connection $connection,
        private EnabledLocale $locales,
    ) {
    }

    /**
     * @return list<string> the codes `summary`, `main_building`, `photo`, in that order
     */
    public function missing(string $venueId): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    EXISTS (SELECT 1 FROM venue_translations t WHERE t.venue_id = :venue AND t.locale = :locale AND btrim(t.summary) <> '') AS summary,
                    EXISTS (SELECT 1 FROM venue_terms vt WHERE vt.venue_id = :venue AND vt.term_group = 'building' AND vt.is_main) AS main_building,
                    EXISTS (SELECT 1 FROM venue_photos p WHERE p.venue_id = :venue AND p.room_id IS NULL) AS photo
                SQL,
            ['venue' => $venueId, 'locale' => $this->locales->default()],
        );
        \assert(false !== $row);

        return array_map(strval(...), array_keys(array_filter($row, static fn (mixed $present): bool => !$present)));
    }
}
