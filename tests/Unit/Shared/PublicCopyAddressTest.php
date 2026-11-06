<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Public copy, mails and `/konto` address the reader with "du" and use Swiss spelling (E4); `lint:translations` checks
 * ICU syntax only. Formal forms are refused case-sensitively, so third-person `sie`/`ihr` in lower case passes; the
 * plural `ihr` counts only after a second-person verb. The staff catalogues and the staff keys in `validators` are not
 * scanned.
 */
final class PublicCopyAddressTest extends TestCase
{
    private const array CATALOGUES = ['favorites', 'inquiries', 'search', 'seo', 'shared', 'validators', 'venues', 'messages', 'geo', 'media', 'portal', 'stats', 'styleguide'];

    private const array REFUSED = [
        '/\b(Sie|Ihnen|Ihr|Ihre|Ihrem|Ihren|Ihrer|Ihres)\b/u',
        '/\b([Ee]uch|[Ee]uer|[Ee]ur(e|en|em|er|es)|([Kk]önnt|[Hh]abt|[Ww]ollt|[Ss]eid) ihr)\b/u',
        '/ß/u',
    ];

    /** The catalogues that speak to the reader, so each has at least one du-form. */
    private const array ADDRESSED = ['favorites', 'inquiries', 'messages', 'portal', 'search', 'seo', 'shared', 'stats', 'styleguide', 'validators'];

    /** `(?!-)` keeps the brand `deine-eventlocation.ch` from counting. */
    private const string DU = '/\b([Dd]u|[Dd]ich|[Dd]ir|[Dd]ein(e|en|em|er|es)?)\b(?!-)/u';

    public function test_public_catalogues_use_du_and_swiss_spelling(): void
    {
        $hits = [];

        foreach (self::CATALOGUES as $domain) {
            $values = $this->values($domain);
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

    public function test_addressed_catalogues_contain_du(): void
    {
        foreach (self::ADDRESSED as $domain) {
            $found = false;
            $values = $this->values($domain);
            array_walk_recursive($values, static function (mixed $value) use (&$found): void {
                $found = $found || (\is_string($value) && 1 === preg_match(self::DU, $value));
            });

            self::assertTrue($found, "{$domain} has no du-form");
        }
    }

    /**
     * @return array<mixed>
     */
    private function values(string $domain): array
    {
        $values = (array) Yaml::parseFile(\dirname(__DIR__, 3)."/translations/{$domain}+intl-icu.de.yaml");
        if ('validators' === $domain) {
            unset($values['cms'], $values['crm']);
        }

        return $values;
    }
}
