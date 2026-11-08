<?php

declare(strict_types=1);

namespace App\Venues;

/**
 * The one rule for whether a published entry may be indexed (G12, D21, D32): staff checked its facts
 * (`venues.facts_checked_at`) and its default-locale description is written. An entry that fails stays online (200)
 * but is `noindex` and is left out of the venue sitemap and llms.txt; landing pages count only entries that pass
 * (`Search\ReadModel\LandingPages`, `LandingQualityGate`). Every reader puts `sql()` into its query, so the detail
 * page's robots meta, the sitemap and the landing counts cannot drift apart.
 */
final class VenueIndexGate
{
    /**
     * The predicate over `venues <venue>` and its default-locale `venue_translations <translation>`; never NULL, so
     * it can be selected as a boolean or used in a `FILTER (WHERE ...)`.
     */
    public static function sql(string $venue = 'v', string $translation = 't'): string
    {
        foreach ([$venue, $translation] as $alias) {
            if (1 !== preg_match('/^[a-z_][a-z0-9_]*$/', $alias)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is no SQL alias.', $alias));
            }
        }

        return "({$venue}.facts_checked_at IS NOT NULL AND COALESCE({$translation}.description, '') ~ '[^[:space:]]')";
    }
}
