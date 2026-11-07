<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * Text from somebody else's website, made safe to store as JSON, to print on a terminal and to keep in a log: valid
 * UTF-8, no control, format (bidi overrides, zero-width), private-use or surrogate characters, one space for any run
 * of whitespace, and a length limit.
 */
final class PlainText
{
    public static function clean(string $text, int $maxCharacters = 600): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = (string) preg_replace('/[\p{Cc}\p{Cf}\p{Co}\p{Cs}]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, $maxCharacters);
    }
}
