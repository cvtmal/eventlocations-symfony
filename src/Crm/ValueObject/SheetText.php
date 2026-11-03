<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * One cell of the owner's customer sheet, made comparable (#48 R1). The sheet was typed and pasted by
 * hand: it holds zero-width spaces, left-to-right marks, no-break and narrow spaces, and names with a
 * stray space at either end. `LegacyVenueText::fold()` keeps U+200B, so the sheet has its own cleaner.
 */
final readonly class SheetText
{
    private function __construct()
    {
    }

    /**
     * NFC; format characters (Cf: U+200B, U+200E, U+202D…) and other control characters dropped; U+00A0,
     * U+202F and U+2004–U+2009 as a space; whitespace collapsed; trimmed. Invalid UTF-8 is scrubbed first,
     * because the normaliser refuses it.
     */
    public static function clean(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = \Normalizer::normalize($text, \Normalizer::FORM_C);
        \assert(\is_string($text));

        $text = (string) preg_replace('/[\x{00A0}\x{202F}\x{2004}-\x{2009}]/u', ' ', $text);
        $text = (string) preg_replace('/\p{Cf}+/u', '', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        // A control character left after the whitespace: Postgres refuses a NUL in a text column.
        $text = (string) preg_replace('/\p{Cc}+/u', '', $text);

        return trim($text, ' ');
    }

    /**
     * The lower-case host of a website cell without its `www.`, or null when the cell names none. A cell
     * without a scheme ("example.ch/hochzeit") is read as `https://`.
     */
    public static function host(?string $website): ?string
    {
        if (null === $website) {
            return null;
        }
        $website = (string) preg_replace('/\s+/u', '', self::clean($website));
        if ('' === $website) {
            return null;
        }
        if (1 !== preg_match('~\A[a-z][a-z0-9+.\-]*://~i', $website)) {
            $website = 'https://'.$website;
        }

        $host = parse_url($website, \PHP_URL_HOST);
        if (!\is_string($host)) {
            return null;
        }
        $host = rtrim(mb_strtolower($host), '.');
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return '' === $host ? null : $host;
    }
}
