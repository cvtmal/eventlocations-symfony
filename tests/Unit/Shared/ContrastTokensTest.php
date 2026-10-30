<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every text/background and focus/background pair of the design tokens meets WCAG 2.x AA (#1, D4).
 * A new colour pair gets a row here.
 */
final class ContrastTokensTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, float}>
     */
    public static function pairs(): iterable
    {
        $text = [
            ['ink-inverse', 'accent'], ['ink-inverse', 'accent-hover'],
            ['ink', 'overlay-region'], ['ink', 'overlay-guests'], ['ink', 'overlay-more'], ['ink', 'overlay-more-bar'], ['ink', 'overlay-more-tile'],
            ['ink', 'surface'], ['ink', 'surface-2'], ['ink', 'surface-3'], ['ink', 'surface-footer'], ['ink', 'bg'],
            ['muted', 'surface-2'], ['ink', 'black'], ['ink-inverse', 'tooltip-bg'],
            ['ink', 'dropdown'],
            ['muted', 'surface'], ['accent-soft', 'surface'],
        ];
        foreach ($text as [$fg, $bg]) {
            yield "text {$fg} on {$bg}" => [$fg, $bg, 4.5];
        }

        // WCAG 1.4.11: the white focus ring against every surface it can sit on.
        foreach (['surface', 'surface-2', 'surface-3', 'bg', 'overlay-region', 'overlay-guests', 'overlay-more', 'overlay-more-tile'] as $bg) {
            yield "focus on {$bg}" => ['focus', $bg, 3.0];
        }

        // WCAG 1.4.11: the borders of the inquiry form's controls on the detail page's surface.
        yield 'control border muted on surface' => ['muted', 'surface', 3.0];
        yield 'control border accent on surface' => ['accent', 'surface', 3.0];
    }

    #[DataProvider('pairs')]
    public function test_pair_meets_the_minimum_contrast(string $foreground, string $background, float $minimum): void
    {
        $ratio = self::ratio($foreground, $background);

        self::assertGreaterThanOrEqual($minimum, $ratio, \sprintf('--c-%s on --c-%s is %.2f:1, needs %.1f:1.', $foreground, $background, $ratio, $minimum));
    }

    public function test_recorded_deviation_ratios_are_reproduced(): void
    {
        self::assertEqualsWithDelta(6.22, self::ratio('ink-inverse', 'accent'), 0.01);
        self::assertEqualsWithDelta(4.66, self::ratio('ink', 'overlay-region'), 0.01);
        self::assertEqualsWithDelta(4.67, self::ratio('ink', 'overlay-more'), 0.01);
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
