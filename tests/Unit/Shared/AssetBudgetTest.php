<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use PHPUnit\Framework\TestCase;

/**
 * S11 budgets for the shipped assets (#1): CSS ≤ 25 KB gzip, JS ≤ 15 KB gzip per page, carousel.js ≤ 2 KB gzip,
 * at most two font files, and self-contained JS modules. Reads the CSS `make sass` builds (a prerequisite of `make test`).
 */
final class AssetBudgetTest extends TestCase
{
    public function test_compiled_css_is_within_25_kb_gzip(): void
    {
        $built = glob(self::root().'/var/sass/app-*.output.css') ?: [];
        if (1 !== \count($built)) {
            self::fail('Run make sass first (expected exactly one var/sass/app-*.output.css).');
        }

        $css = (string) preg_replace('~/\*# sourceMappingURL=.*?\*/~s', '', (string) file_get_contents($built[0]));

        self::assertLessThanOrEqual(25 * 1024, self::gzipSize($css));
    }

    public function test_carousel_js_is_within_2_kb_gzip(): void
    {
        self::assertLessThanOrEqual(2048, self::gzipSize((string) file_get_contents(self::root().'/assets/js/carousel.js')));
    }

    public function test_page_javascript_is_within_15_kb_gzip(): void
    {
        $total = 0;
        foreach (self::scripts() as $file) {
            $total += self::gzipSize((string) file_get_contents($file));
        }

        self::assertLessThanOrEqual(15 * 1024, $total);
    }

    public function test_at_most_two_font_files_ship(): void
    {
        self::assertCount(2, glob(self::root().'/assets/fonts/*.woff2') ?: []);
    }

    public function test_javascript_modules_are_self_contained(): void
    {
        foreach (self::scripts() as $file) {
            $js = (string) file_get_contents($file);

            self::assertDoesNotMatchRegularExpression('/^\s*import\s/m', $js, basename($file).' has no import.');
            self::assertDoesNotMatchRegularExpression('/\bexport\s/', $js, basename($file).' has no export.');
            self::assertStringNotContainsString('console.', $js, basename($file).' does not log.');
        }
    }

    /**
     * @return list<string>
     */
    private static function scripts(): array
    {
        $files = glob(self::root().'/assets/js/*.js') ?: [];
        self::assertNotEmpty($files);

        return $files;
    }

    private static function gzipSize(string $content): int
    {
        return \strlen((string) gzencode($content, 9));
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
