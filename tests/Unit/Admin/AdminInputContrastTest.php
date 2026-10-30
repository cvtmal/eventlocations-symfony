<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * WCAG 1.4.11: on a white card the border is all that identifies an input, so it needs 3:1 against each
 * surface an input can sit on. `--a-field`, which buttons keep, has 1.5:1. A deviation from the approved
 * reference (research D15). `ContrastTokensTest` reads only the public `--c-*` tokens.
 */
final class AdminInputContrastTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function surfaces(): iterable
    {
        yield 'surface' => ['--a-surface'];
        yield 'surface-muted' => ['--a-surface-muted'];
        yield 'bg' => ['--a-bg'];
    }

    #[DataProvider('surfaces')]
    public function test_the_input_border_stands_out_on_the_surface(string $surface): void
    {
        $ratio = self::ratio(self::token('--a-field-strong'), self::token($surface));

        self::assertGreaterThanOrEqual(3.0, $ratio, \sprintf('--a-field-strong on %s is %.2f:1.', $surface, $ratio));
    }

    public function test_inputs_use_the_strong_border(): void
    {
        $partial = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/styles/admin/_field.scss');

        self::assertStringContainsString('border: 1px solid var(--a-field-strong);', $partial);
    }

    private static function token(string $name): string
    {
        $tokens = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/styles/admin/_tokens.scss');
        if (1 !== preg_match('/'.preg_quote($name, '/').':\s*(#[0-9a-f]{3,6});/i', $tokens, $match)) {
            self::fail($name.' is no token.');
        }

        return $match[1];
    }

    private static function ratio(string $foreground, string $background): float
    {
        $lighter = max(self::luminance($foreground), self::luminance($background));
        $darker = min(self::luminance($foreground), self::luminance($background));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $digits = ltrim($hex, '#');
        if (3 === \strlen($digits)) {
            $digits = $digits[0].$digits[0].$digits[1].$digits[1].$digits[2].$digits[2];
        }

        $channels = array_map(static function (string $pair): float {
            $value = hexdec($pair) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split($digits, 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
