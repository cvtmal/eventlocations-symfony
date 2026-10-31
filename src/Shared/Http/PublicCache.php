<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * The cache lifetimes of every public route (S10, #19). Every purge empties the whole
 * HttpCache store, so a page can stay a day: a change shows at once, and a day keeps
 * Neon asleep between visits instead of waking it every ten minutes.
 *
 * Listings expire at the next midnight in Europe/Zurich, because their order uses the
 * day's seed (ListingQuery): page 1 and page 2 cached on different days would repeat or
 * skip venues. A missing page (404, 410) is kept for an hour.
 */
final class PublicCache
{
    public const PAGE_TTL = 86400;
    public const STALE_TTL = 86400;
    public const MISSING_TTL = 3600;
    public const COUNT_BROWSER_TTL = 60;
    public const PLACES_BROWSER_TTL = 3600;

    private const TIMEZONE = 'Europe/Zurich';

    /**
     * @return array{public: true, s_maxage: int, stale_while_revalidate: int, stale_if_error: int}
     */
    public static function page(): array
    {
        return [
            'public' => true,
            's_maxage' => self::PAGE_TTL,
            'stale_while_revalidate' => self::STALE_TTL,
            'stale_if_error' => self::STALE_TTL,
        ];
    }

    /**
     * @return array{public: true, s_maxage: int, stale_while_revalidate: int, stale_if_error: int}
     */
    public static function listing(\DateTimeImmutable $now): array
    {
        return ['s_maxage' => self::secondsUntilMidnight($now)] + self::page();
    }

    /**
     * Seconds to the next 00:00 in Europe/Zurich, at least 1 and at most a day (a 25-hour
     * day at the end of summer time caps at PAGE_TTL).
     */
    public static function secondsUntilMidnight(\DateTimeImmutable $now): int
    {
        $local = $now->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $midnight = $local->modify('tomorrow');

        return max(1, min(self::PAGE_TTL, $midnight->getTimestamp() - $now->getTimestamp()));
    }
}
