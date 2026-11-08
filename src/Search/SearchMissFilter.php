<?php

declare(strict_types=1);

namespace App\Search;

/**
 * What the listing may log of a search without results (G12): the canonical query, with the search text cut to
 * `MAX_TEXT` characters (D45; multibyte-safe, so a cut never splits a character), or nothing at all when the text
 * looks like contact data (D46): an "@" or a run of six or more digits, which spaces, dots, dashes and slashes may
 * separate ("079 123 45 67", "044/123.45.67"). Only the text is checked: the other values are codes, numbers and a
 * postcode or town id that `SearchCriteria` already validated. /datenschutz says both (`legal.privacy.stats.body`).
 */
final class SearchMissFilter
{
    public const int MAX_TEXT = 100;

    /**
     * @param array<string, string|list<string>> $query `SearchCriteria::toQuery()`
     *
     * @return string|null the URL-encoded query to log, null when nothing may be logged
     */
    public static function query(array $query): ?string
    {
        if (isset($query['q']) && \is_string($query['q'])) {
            if (self::looksLikeContactData($query['q'])) {
                return null;
            }
            $query['q'] = rtrim(mb_substr($query['q'], 0, self::MAX_TEXT));
        }

        return [] === $query ? null : http_build_query($query);
    }

    public static function looksLikeContactData(string $text): bool
    {
        // `0 !==`: invalid UTF-8 makes preg_match return false, and a text we cannot read is not logged either.
        return 0 !== preg_match('~@|\d(?:[\s./-]*\d){5}~u', $text);
    }
}
