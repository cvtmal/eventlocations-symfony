<?php

declare(strict_types=1);

namespace App\Seo;

/**
 * Venue text inside the Markdown views (`/llms.txt`, `/hochzeitslocation/{slug}.md`): staff and
 * import text is data, so none of it may become a heading, a list, a link or emphasis. Every
 * character CommonMark could read as syntax gets a backslash, which CommonMark removes again.
 */
final class MarkdownText
{
    /**
     * Characters that are syntax anywhere in a line. `&` (entities), `~` and `|` (GFM) stay
     * as they are: they cannot open a link or a block, and a reader sees "Apéro & Party", not
     * "Apéro \& Party".
     */
    private const string INLINE = '/[\\\\`*_\[\]<>]/u';

    /**
     * What opens a block at the start of a line and INLINE leaves alone: a heading `#`, a list
     * `-` or `+` before a space, a line of only `=` or `-` (setext underline, thematic break).
     * A quote's `>` is INLINE's already.
     */
    private const string BLOCK_MARKER = '/^([ \t]*)(#|[+-](?=[ \t]|$)|[=-](?=[=\- \t]*$))/mu';

    /** An ordered-list marker (`1.`, `1)` before a space): the punctuation is escaped, so "6.2 km" stays as it is. */
    private const string ORDERED_MARKER = '/^([ \t]*\d{1,9})([.)])(?=[ \t]|$)/mu';

    /**
     * One line: whitespace and line breaks collapse to single spaces, so a value cannot end a
     * list item or start a new block.
     */
    public static function inline(string $text): string
    {
        return self::escape(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * Paragraphs, as the detail page shows them: split at blank lines, each line kept. Lines are
     * trimmed, so indentation cannot turn into a code block.
     */
    public static function block(string $text): string
    {
        $paragraphs = [];
        foreach (preg_split('/\R\s*\R/u', trim($text)) ?: [] as $paragraph) {
            $lines = array_map(
                static fn (string $line): string => trim((string) preg_replace('/[ \t]+/u', ' ', $line)),
                preg_split('/\R/u', trim($paragraph)) ?: [],
            );
            $lines = array_filter($lines, static fn (string $line): bool => '' !== $line);
            if ([] !== $lines) {
                $paragraphs[] = self::escape(implode("\n", $lines));
            }
        }

        return implode("\n\n", $paragraphs);
    }

    /**
     * A link destination. A plain URL stays bare, because llms.txt readers parse `[name](url)`
     * with a regex; one with whitespace, a parenthesis or an angle bracket goes in angle
     * brackets, which may hold parentheses, with spaces and angle brackets percent-encoded.
     */
    public static function url(string $url): string
    {
        $url = trim($url);
        if (1 === preg_match('/^[^\s()<>]+$/u', $url)) {
            return $url;
        }

        return '<'.str_replace([' ', '<', '>', "\n", "\r", "\t"], ['%20', '%3C', '%3E', '', '', ''], $url).'>';
    }

    /**
     * A rendered Markdown template as the response body: Twig's tags leave blank-line runs
     * behind, so they collapse to one blank line, trailing spaces go, and it ends in one newline.
     */
    public static function tidy(string $markdown): string
    {
        $markdown = (string) preg_replace('/[ \t]+$/mu', '', $markdown);

        return trim((string) preg_replace('/\n{3,}/u', "\n\n", $markdown))."\n";
    }

    private static function escape(string $text): string
    {
        $text = (string) preg_replace(self::INLINE, '\\\\$0', $text);
        $text = (string) preg_replace(self::BLOCK_MARKER, '$1\\\\$2', $text);

        return (string) preg_replace(self::ORDERED_MARKER, '$1\\\\$2', $text);
    }
}
