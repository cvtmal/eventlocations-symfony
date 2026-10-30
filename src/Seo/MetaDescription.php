<?php

declare(strict_types=1);

namespace App\Seo;

/**
 * SEO-1: a meta description ≤ 155 characters that ends at a sentence where it can.
 */
final class MetaDescription
{
    public const int MAX = 155;

    /** A shorter prefix is an abbreviation (`ca. 120 Gäste`) rather than a sentence. */
    private const int MIN_SENTENCE = 70;

    private const array SENTENCE_ENDS = ['.', '!', '?'];

    /** Words whose full stop ends no sentence (`bei St. Gallen`), lower case, without the stop. */
    private const array ABBREVIATIONS = ['st', 'ca', 'nr', 'inkl', 'bzw', 'evtl', 'ggf', 'tel', 'usw', 'z.b', 'u.a', 'd.h'];

    public static function fit(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= self::MAX) {
            return $text;
        }

        $chars = mb_str_split(mb_substr($text, 0, self::MAX + 1));
        for ($length = self::MAX; $length >= self::MIN_SENTENCE; --$length) {
            if (' ' === $chars[$length] && \in_array($chars[$length - 1], self::SENTENCE_ENDS, true) && !self::endsWithAbbreviation($chars, $length - 1)) {
                return implode('', \array_slice($chars, 0, $length));
            }
        }

        $head = mb_substr($text, 0, self::MAX);
        $lastSpace = mb_strrpos($head, ' ');

        return mb_substr($head, 0, false === $lastSpace ? self::MAX - 1 : $lastSpace).'…';
    }

    /**
     * @param list<string> $chars
     */
    private static function endsWithAbbreviation(array $chars, int $stop): bool
    {
        if ('.' !== $chars[$stop]) {
            return false;
        }

        $start = $stop;
        while ($start > 0 && ' ' !== $chars[$start - 1]) {
            --$start;
        }
        $word = (string) preg_replace('/^\P{L}+/u', '', implode('', \array_slice($chars, $start, $stop - $start)));

        return \in_array(mb_strtolower($word), self::ABBREVIATIONS, true);
    }
}
