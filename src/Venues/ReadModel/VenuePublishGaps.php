<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Shared\Translation\EnabledLocale;
use App\Venues\VenueClaims;
use Doctrine\DBAL\Connection;

/**
 * What a venue still lacks to go online (CMS-3, D14): a summary in the default locale, a main building and at least
 * one gallery photo (`room_id IS NULL`; a room's photo is not on the card) and an inquiry address (D60: D2 promises an
 * inquiry form on every page). The one rule, shared by `PublishVenue`, the portal's submit and wizard.
 *
 * The draft rule (F14, D32(2), D45, D54): an entry nobody answers for (no active `/konto` manager, `VenueClaims`)
 * goes online only when it may also be indexed (`VenueIndexGate`): staff checked its facts and its default-locale
 * description is written. An imported or crawled entry therefore stays a draft until both are done. An entry with a
 * manager keeps G12's rule: online even unchecked, `noindex` until both are done.
 */
final readonly class VenuePublishGaps
{
    public function __construct(
        private Connection $connection,
        private EnabledLocale $locales,
        private VenueClaims $claims,
    ) {
    }

    /**
     * @return list<string> the codes `summary`, `main_building`, `photo`, `inquiry_email`, `facts`, `description`, in that order;
     *                      the last two only for an entry without an active manager
     */
    public function missing(string $venueId): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    EXISTS (SELECT 1 FROM venue_translations t WHERE t.venue_id = :venue AND t.locale = :locale AND btrim(t.summary) <> '') AS summary,
                    EXISTS (SELECT 1 FROM venue_terms vt WHERE vt.venue_id = :venue AND vt.term_group = 'building' AND vt.is_main) AS main_building,
                    EXISTS (SELECT 1 FROM venue_photos p WHERE p.venue_id = :venue AND p.room_id IS NULL) AS photo,
                    EXISTS (SELECT 1 FROM venues v WHERE v.id = :venue AND v.inquiry_email IS NOT NULL) AS inquiry_email,
                    EXISTS (SELECT 1 FROM venues v WHERE v.id = :venue AND v.facts_checked_at IS NOT NULL) AS facts,
                    EXISTS (SELECT 1 FROM venue_translations t WHERE t.venue_id = :venue AND t.locale = :locale AND COALESCE(t.description, '') ~ '[^[:space:]]') AS description
                SQL,
            ['venue' => $venueId, 'locale' => $this->locales->default()],
        );
        \assert(false !== $row);

        $missing = array_map(strval(...), array_keys(array_filter($row, static fn (mixed $present): bool => !$present)));
        $draftRule = array_values(array_intersect($missing, ['facts', 'description']));
        if ([] === $draftRule || !$this->claims->isConfirmed($venueId)) {
            return $missing;
        }

        return array_values(array_diff($missing, $draftRule));
    }
}
