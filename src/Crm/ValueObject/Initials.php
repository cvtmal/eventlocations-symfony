<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * The avatar letters of a person or company. A person: the first letters of the first and the last word,
 * as `AdminIdentity::initials()` (a security user, so not shared). A company: of the first two words, so a
 * legal form at the end does not count ("Seeblick Events GmbH" → SE, Company.dc.html). A single word gives its
 * first two letters.
 */
final readonly class Initials
{
    private function __construct()
    {
    }

    public static function person(string $name): string
    {
        $words = self::words($name);

        return self::letters($words, $words[\count($words) - 1] ?? '');
    }

    public static function company(string $name): string
    {
        $words = self::words($name);

        return self::letters($words, $words[1] ?? '');
    }

    /**
     * @return list<string>
     */
    private static function words(string $name): array
    {
        return preg_split('/[\s\-]+/u', trim($name), flags: \PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @param list<string> $words
     */
    private static function letters(array $words, string $second): string
    {
        if ([] === $words) {
            return '';
        }
        if (1 === \count($words)) {
            return mb_strtoupper(mb_substr($words[0], 0, 2));
        }

        return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($second, 0, 1));
    }
}
