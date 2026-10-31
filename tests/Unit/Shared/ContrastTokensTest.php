<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every text/background, focus/background and line/background pair of the design tokens meets WCAG 2.x AA (S15, #35).
 * A new colour pair gets a row here. Two pairs stay out on purpose, as `docs/reference/public/README.md` records:
 * map-land on overlay-ground (the cantons are told apart by map-line) and map-hover on map-lake (by hue and the tooltip).
 */
final class ContrastTokensTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, float}>
     */
    public static function pairs(): iterable
    {
        $text = [
            ['ink', 'surface'], ['ink', 'surface-raised'], ['ink', 'surface-alt'], ['ink', 'accent-soft'],
            ['ink', 'overlay-ground'], ['ink', 'overlay-circle'], ['ink', 'tier-ground'], ['ink', 'tag-ground'],
            ['ink-muted', 'surface'], ['ink-muted', 'surface-raised'], ['ink-muted', 'surface-alt'],
            ['accent-strong', 'surface'], ['accent-strong', 'surface-raised'], ['accent-strong', 'surface-alt'],
            ['on-accent', 'accent-strong'], ['on-accent', 'accent-strong-hover'], ['on-accent', 'accent-strong-active'],
            ['accent-strong-hover', 'accent-soft'], ['accent-strong-active', 'accent-soft'],
            ['error', 'surface'], ['error', 'surface-raised'], ['error', 'error-soft'],
            ['success', 'surface'], ['success', 'surface-raised'], ['success', 'success-soft'],
            ['disabled-ink', 'disabled-ground'],
            ['surface-raised', 'ink'], // the preview banner
        ];
        foreach ($text as [$fg, $bg]) {
            yield "text {$fg} on {$bg}" => [$fg, $bg, 4.5];
        }

        // WCAG 1.4.11: the logo and icon pink, the focus ring and the input underline on the three page grounds.
        foreach (['accent', 'focus', 'line'] as $fg) {
            foreach (['surface', 'surface-raised', 'surface-alt'] as $bg) {
                yield "{$fg} on {$bg}" => [$fg, $bg, 3.0];
            }
        }

        // WCAG 1.4.11: the canton map on its land and on the overlay's ground.
        $map = [
            ['map-line', 'map-land'], ['map-line', 'overlay-ground'],
            ['map-hover', 'map-land'], ['map-hover', 'overlay-ground'], ['map-hover', 'map-selected'],
            ['map-selected', 'map-land'], ['map-selected', 'overlay-ground'],
            ['map-lake', 'map-land'], ['map-lake', 'overlay-ground'], ['map-lake', 'map-selected'],
        ];
        foreach ($map as [$fg, $bg]) {
            yield "{$fg} on {$bg}" => [$fg, $bg, 3.0];
        }
    }

    #[DataProvider('pairs')]
    public function test_pair_meets_the_minimum_contrast(string $foreground, string $background, float $minimum): void
    {
        $ratio = self::ratio($foreground, $background);

        self::assertGreaterThanOrEqual($minimum, $ratio, \sprintf('--c-%s on --c-%s is %.2f:1, needs %.1f:1.', $foreground, $background, $ratio, $minimum));
    }

    public function test_recorded_ratios_are_reproduced(): void
    {
        self::assertEqualsWithDelta(5.26, self::ratio('on-accent', 'accent-strong'), 0.01);
        self::assertEqualsWithDelta(7.63, self::ratio('ink', 'accent-soft'), 0.01);
        self::assertEqualsWithDelta(4.63, self::ratio('accent-strong', 'surface-alt'), 0.01);
    }

    private static function ratio(string $foreground, string $background): float
    {
        $tokens = self::tokens();
        self::assertArrayHasKey($foreground, $tokens, "Token --c-{$foreground} is a hex colour in _tokens.scss.");
        self::assertArrayHasKey($background, $tokens, "Token --c-{$background} is a hex colour in _tokens.scss.");

        $l1 = self::luminance($tokens[$foreground]);
        $l2 = self::luminance($tokens[$background]);

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        if (3 === \strlen($hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        $channels = array_map(
            static function (string $pair): float {
                $c = hexdec($pair) / 255;

                return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            },
            str_split($hex, 2),
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * @return array<string, string> token name without `--c-` => hex without `#`
     */
    private static function tokens(): array
    {
        $scss = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/styles/abstracts/_tokens.scss');
        preg_match_all('/--c-([a-z0-9-]+):\s*#([0-9a-f]{6}|[0-9a-f]{3})\s*;/i', $scss, $matches, \PREG_SET_ORDER);

        $tokens = [];
        foreach ($matches as $match) {
            $tokens[$match[1]] = strtolower($match[2]);
        }

        return $tokens;
    }
}
