<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * S11 budgets for the shipped assets (#1): CSS ≤ 25 KB gzip, JS ≤ 15 KB gzip per page, carousel.js ≤ 2 KB gzip,
 * the Region overlay's JS ≤ 6 KB gzip (#10), near-me.js ≤ 4 KB gzip (#52), google-rating.js ≤ 2 KB gzip (#53), at most
 * two font files, and self-contained JS modules. Reads the CSS `make sass` builds (a prerequisite of `make test`).
 *
 * The JS budget is per page, as S11 words it (#47): a page loads base.html.twig's scripts plus its own template's.
 * Every public script must be referenced by base, one of PAGES, the back office's scripts or the styleguide, so a
 * new script cannot escape the measure. The near-me page (#52) counts base + near-me.js: the Google Maps script it
 * loads at runtime is not ours and not referenced through asset('js/…'), S1's stated exception. The same holds for the
 * detail page (#53): it counts our loader google-rating.js, not Google's script and element, which it loads on view.
 */
#[Group('budget')]
final class AssetBudgetTest extends TestCase
{
    private const array PAGES = [
        'search/home.html.twig',
        'search/listing.html.twig',
        'venues/detail/show.html.twig',
        'favorites/merkliste.html.twig',
        'search/nearby.html.twig',
    ];

    /** Templates outside PAGES that load public scripts, which count as measured there. */
    private const array OTHER_LOADERS = [
        'admin/components/_scripts.html.twig',
        'styleguide/index.html.twig',
    ];

    public function test_compiled_css_is_within_25_kb_gzip(): void
    {
        self::assertLessThanOrEqual(25 * 1024, self::gzipSize(self::compiledCss()));
    }

    public function test_first_view_loads_at_most_two_font_files(): void
    {
        self::assertLessThanOrEqual(2, substr_count(self::compiledCss(), '@font-face'));

        $layout = (string) file_get_contents(self::root().'/templates/base.html.twig');
        self::assertLessThanOrEqual(2, preg_match_all('~<link\b[^>]*rel="preload"[^>]*as="font"~', $layout));
    }

    public function test_carousel_js_is_within_2_kb_gzip(): void
    {
        self::assertLessThanOrEqual(2048, self::gzipSize((string) file_get_contents(self::root().'/assets/js/carousel.js')));
    }

    public function test_near_me_js_is_within_4_kb_gzip(): void
    {
        self::assertLessThanOrEqual(4096, self::gzipSize((string) file_get_contents(self::root().'/assets/js/near-me.js')));
    }

    public function test_google_rating_js_is_within_2_kb_gzip(): void
    {
        self::assertLessThanOrEqual(2048, self::gzipSize((string) file_get_contents(self::root().'/assets/js/google-rating.js')));
    }

    public function test_region_overlay_javascript_is_within_6_kb_gzip(): void
    {
        $total = 0;
        foreach (['overlay.js', 'live-count.js', 'region-filter.js'] as $file) {
            $total += self::gzipSize((string) file_get_contents(self::root().'/assets/js/'.$file));
        }

        self::assertLessThanOrEqual(6 * 1024, $total);
    }

    #[DataProvider('pages')]
    public function test_page_javascript_is_within_15_kb_gzip(string $template): void
    {
        $total = 0;
        foreach (array_unique([...self::referencedScripts('base.html.twig'), ...self::referencedScripts($template)]) as $script) {
            $file = self::root().'/assets/js/'.$script;
            self::assertFileExists($file);
            $total += self::gzipSize((string) file_get_contents($file));
        }

        self::assertLessThanOrEqual(15 * 1024, $total, $template);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        foreach (self::PAGES as $template) {
            yield $template => [$template];
        }
    }

    public function test_every_public_script_is_measured_on_a_page(): void
    {
        $measured = [];
        foreach (['base.html.twig', ...self::PAGES, ...self::OTHER_LOADERS] as $template) {
            $measured = [...$measured, ...self::referencedScripts($template)];
        }

        foreach (self::scripts() as $file) {
            self::assertContains(basename($file), $measured, basename($file).' is loaded by a measured template.');
        }
    }

    public function test_at_most_two_font_files_ship(): void
    {
        $files = glob(self::root().'/assets/fonts/*.woff2') ?: [];

        self::assertNotEmpty($files);
        self::assertLessThanOrEqual(2, \count($files));
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

    /**
     * The top-level public scripts a template loads through asset('js/…').
     *
     * @return list<string>
     */
    private static function referencedScripts(string $template): array
    {
        $twig = (string) file_get_contents(self::root().'/templates/'.$template);
        preg_match_all("~asset\\('js/([a-z0-9-]+\\.js)'\\)~", $twig, $matches);

        return $matches[1];
    }

    private static function compiledCss(): string
    {
        $built = glob(self::root().'/var/sass/app-*.output.css') ?: [];
        if (1 !== \count($built)) {
            self::fail('Run make sass first (expected exactly one var/sass/app-*.output.css).');
        }

        return (string) preg_replace('~/\*# sourceMappingURL=.*?\*/~s', '', (string) file_get_contents($built[0]));
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
