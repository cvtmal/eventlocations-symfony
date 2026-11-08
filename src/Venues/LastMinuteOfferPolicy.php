<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\ValueObject\KnownTiers;

/**
 * The one rule for which offers «Kurzfristig frei» (G14, D55) the public sees: the offer's last day is the Zurich day
 * of the request or later, and the plan of the term that covers that day allows offers (`last_minute_allowed`, Free
 * without a term). It is the `EntitlementResolver`'s lateral term join in SQL, so a lapsed or voided Pro term hides
 * its venue's offers at the first page rendered after Zurich midnight, before the nightly jobs move `venues.tier_id`
 * or delete the row, and needs no purge of its own (the store is cleared at 00:05).
 *
 * The page «Kurzfristig frei» and the sitemap put `sql()` in the WHERE of their offer query. The card badge and the
 * detail section filter by `dateSql()` in SQL and by `Entitlements::$lastMinuteAllowed` in PHP, which is the same rule
 * with the resolver call the page makes anyway.
 */
final class LastMinuteOfferPolicy
{
    /** The aliases of the predicate's own tables: the term and the two tiers. */
    private const array OWN_ALIASES = ['lmp_s', 'lmp_st', 'lmp_t'];

    private function __construct()
    {
    }

    /**
     * The whole rule for a query over `last_minute_offers <offerAlias>`, with the Zurich day bound by the caller to
     * `:<todayParam>` (`TermDay::sql($now)`).
     */
    public static function sql(string $offerAlias, string $todayParam): string
    {
        self::assertAlias($offerAlias);
        self::assertAlias($todayParam);

        return \sprintf(
            '(%1$s AND COALESCE((SELECT lmp_st.last_minute_allowed FROM subscriptions lmp_s JOIN tiers lmp_st ON lmp_st.id = lmp_s.tier_id'
            .' WHERE lmp_s.venue_id = %2$s.venue_id AND lmp_s.starts_on <= :%3$s AND lmp_s.ends_on >= :%3$s ORDER BY lmp_st.rank_weight DESC, lmp_s.starts_on LIMIT 1),'
            ." (SELECT lmp_t.last_minute_allowed FROM tiers lmp_t WHERE lmp_t.id = '%4\$s')))",
            self::dateSql($offerAlias, $todayParam),
            $offerAlias,
            $todayParam,
            KnownTiers::FREE_ID,
        );
    }

    /**
     * The date half of the rule: the offer's last day is today or later.
     */
    public static function dateSql(string $offerAlias, string $todayParam): string
    {
        self::assertAlias($offerAlias);
        self::assertAlias($todayParam);

        return \sprintf('%s.ends_on >= :%s', $offerAlias, $todayParam);
    }

    private static function assertAlias(string $alias): void
    {
        // The D modifier: without it `$` also matches before a final newline.
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/D', $alias)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a plain SQL alias.', $alias));
        }
        if (\in_array($alias, self::OWN_ALIASES, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is an alias of the predicate\'s own tables (%s).', $alias, implode(', ', self::OWN_ALIASES)));
        }
    }
}
