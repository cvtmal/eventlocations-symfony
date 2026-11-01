<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Public copy addresses the reader with "Sie" and uses Swiss spelling (BRD-4, #40); `lint:translations` checks ICU
 * syntax only. `ihr` counts only after a second-person verb, because a bare `ihr` is also correct third-person German.
 * `Versuche` and `Vergleiche` count only with their next word, because both are also plural nouns. The staff catalogues
 * and the staff keys in `validators` stay informal.
 */
final class PublicCopyAddressTest extends TestCase
{
    private const array CATALOGUES = ['favorites', 'inquiries', 'search', 'seo', 'shared', 'validators', 'venues', 'messages', 'geo', 'media'];

    private const array REFUSED = [
        '/\b([Dd]u|[Dd]ich|[Dd]ir|[Dd]ein[a-z]*|[Ee]uch|[Ee]uer|[Ee]ur(e|en|em|er|es)|([Kk]önnt|[Hh]abt|[Ww]ollt|[Ss]eid) ihr)\b/u',
        '/\b(gib|[Pp]rüfe|sende|versuche|Versuche es|[Ss]chreib|[Ww]ähle|vergleiche|Vergleiche die|frag|nimm|Antworte|[Ee]ntferne|Finde|Entdecke|Suche eine)\b/u',
        '/ß/u',
    ];

    public function test_public_catalogues_use_sie_and_swiss_spelling(): void
    {
        $hits = [];

        foreach (self::CATALOGUES as $domain) {
            $values = (array) Yaml::parseFile(\dirname(__DIR__, 3)."/translations/{$domain}+intl-icu.de.yaml");
            if ('validators' === $domain) {
                unset($values['cms'], $values['crm']);
            }

            array_walk_recursive($values, static function (mixed $value) use ($domain, &$hits): void {
                foreach (self::REFUSED as $pattern) {
                    if (\is_string($value) && 1 === preg_match($pattern, $value)) {
                        $hits[] = "{$domain}: {$value}";
                    }
                }
            });
        }

        self::assertSame([], $hits);
    }
}
