<?php

declare(strict_types=1);

namespace App\Tests\Support\Translation;

use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * Key parity between the reference locale and every other enabled locale. debug:translation
 * only sees keys used in code, so it misses a key that exists in the German catalogue alone.
 * Only app domains count (those with a reference-locale file in the given directories), so
 * Symfony's bundled catalogues (validators, security) never produce gaps.
 */
final class CatalogueParity
{
    /**
     * @param list<string> $dirs
     *
     * @return list<string>
     */
    public static function domainsIn(array $dirs, string $referenceLocale): array
    {
        $suffix = '.'.$referenceLocale.'.yaml';
        $domains = [];
        foreach ($dirs as $dir) {
            foreach (glob($dir.'/*'.$suffix) ?: [] as $file) {
                $domain = substr(basename($file), 0, -\strlen($suffix));
                if (str_ends_with($domain, '+intl-icu')) {
                    $domain = substr($domain, 0, -\strlen('+intl-icu'));
                }
                $domains[$domain] = $domain;
            }
        }

        $domains = array_values($domains);
        sort($domains);

        return $domains;
    }

    /**
     * @param list<string> $locales
     * @param list<string> $domains
     *
     * @return array<string, array{missing: list<string>, extra: list<string>}>
     */
    public static function diff(TranslatorBagInterface $translator, string $referenceLocale, array $locales, array $domains): array
    {
        $gaps = [];
        foreach ($locales as $locale) {
            if ($locale === $referenceLocale) {
                continue;
            }

            foreach ($domains as $domain) {
                $reference = self::keys($translator, $referenceLocale, $domain);
                $translated = self::keys($translator, $locale, $domain);

                $missing = array_values(array_diff($reference, $translated));
                $extra = array_values(array_diff($translated, $reference));
                if ([] !== $missing || [] !== $extra) {
                    $gaps[$locale.'/'.$domain] = ['missing' => $missing, 'extra' => $extra];
                }
            }
        }

        return $gaps;
    }

    /**
     * @return list<string>
     */
    private static function keys(TranslatorBagInterface $translator, string $locale, string $domain): array
    {
        $keys = array_map('strval', array_keys($translator->getCatalogue($locale)->all($domain)));
        sort($keys);

        return $keys;
    }
}
