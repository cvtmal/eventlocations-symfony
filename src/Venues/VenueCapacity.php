<?php

declare(strict_types=1);

namespace App\Venues;

/**
 * The one capacity rule of every public place that shows or publishes a venue's guests (F5b):
 * the listing card, the detail page's head, its JSON-LD and markdown view, the similar-venues
 * band and `/llms.txt`. The range spans the venue's own range and every room range with a
 * maximum, as the guest filter does (`ListingSql`): the maximum is the greatest maximum, the
 * minimum the least minimum, NULL ("up to") once one of those ranges states none.
 *
 * The expressions read `venues <alias>` and use `cap_r` for the rooms, so a caller must not.
 *
 * `guestsFitSql()` is the F5b guest filter itself: the listing (`ListingSql`) and the inquiry qualification (G7, D56)
 * share it, so a venue the filter shows for N guests is one an inquiry for N guests fits.
 */
final class VenueCapacity
{
    private const string ROOM_ALIAS = 'cap_r';

    private function __construct()
    {
    }

    public static function maxSql(string $venueAlias): string
    {
        $v = self::alias($venueAlias);
        $r = self::ROOM_ALIAS;

        return "GREATEST({$v}.capacity_max, (SELECT max({$r}.capacity_max) FROM rooms {$r} WHERE {$r}.venue_id = {$v}.id))";
    }

    public static function minSql(string $venueAlias): string
    {
        $v = self::alias($venueAlias);
        $r = self::ROOM_ALIAS;

        return "CASE WHEN {$v}.capacity_min IS NULL THEN NULL ELSE ("
            ."SELECT CASE WHEN bool_or({$r}.capacity_min IS NULL) THEN NULL ELSE LEAST({$v}.capacity_min, min({$r}.capacity_min)) END "
            ."FROM rooms {$r} WHERE {$r}.venue_id = {$v}.id AND {$r}.capacity_max IS NOT NULL) END";
    }

    /**
     * True when the venue's own range or one of its rooms' ranges holds `:<param>` guests: a maximum of at least
     * that many and no higher minimum. A room's range is the one F4 derives from its layouts.
     */
    public static function guestsFitSql(string $venueAlias, string $param): string
    {
        $v = self::alias($venueAlias);
        $r = self::ROOM_ALIAS;
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/D', $param)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a plain SQL parameter name.', $param));
        }
        $p = ':'.$param;

        return "(({$v}.capacity_max >= {$p} AND ({$v}.capacity_min IS NULL OR {$v}.capacity_min <= {$p}))"
            ." OR EXISTS (SELECT 1 FROM rooms {$r} WHERE {$r}.venue_id = {$v}.id AND {$r}.capacity_max >= {$p} AND ({$r}.capacity_min IS NULL OR {$r}.capacity_min <= {$p})))";
    }

    private static function alias(string $venueAlias): string
    {
        // The D modifier: without it `$` also matches before a final newline.
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/D', $venueAlias) || self::ROOM_ALIAS === $venueAlias) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a plain SQL alias of the venues table.', $venueAlias));
        }

        return $venueAlias;
    }
}
