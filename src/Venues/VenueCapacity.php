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

    private static function alias(string $venueAlias): string
    {
        // The D modifier: without it `$` also matches before a final newline.
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/D', $venueAlias) || self::ROOM_ALIAS === $venueAlias) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a plain SQL alias of the venues table.', $venueAlias));
        }

        return $venueAlias;
    }
}
