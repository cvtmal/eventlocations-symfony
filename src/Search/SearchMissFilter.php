<?php

declare(strict_types=1);

namespace App\Search;

/**
 * What the listing may log of a search without results (G12): the canonical query, with the search text cut to
 * `MAX_TEXT` characters (D46; multibyte-safe, so a cut never splits a character), or nothing at all when the text
 * looks like contact data (D46): an "@" or a run of `MIN_DIGITS` or more digits, which spaces, dots, commas, dashes,
 * underscores, slashes and brackets may separate ("079 123 45 67", "044/123.45.67", "(044) 123 45 67"). Only the text
 * is checked: the other values are codes, numbers and a postcode or town id that `SearchCriteria` already validated.
 * /datenschutz says both (`legal.privacy.stats.body`).
 */
final class SearchMissFilter
{
    public const int MAX_TEXT = 100;

    public const int MIN_DIGITS = 6;

    /**
     * @param array<string, string|list<string>> $query   `SearchCriteria::toQuery()`
     * @param mixed                              $rawText the request's own `q`, before `SearchCriteria` cut it to its
     *                                                    length: a number that crosses the cut is still found
     *
     * @return string|null the URL-encoded query to log, null when nothing may be logged
     */
    public static function query(array $query, mixed $rawText = null): ?string
    {
        if (\is_string($rawText) && self::looksLikeContactData($rawText)) {
            return null;
        }
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
        // U+FF20 is the fullwidth "@".
        return 0 !== preg_match(\sprintf('~[@\x{FF20}]|\d(?:[\s.,_()/-]*\d){%d}~u', self::MIN_DIGITS - 1), $text);
    }
}
