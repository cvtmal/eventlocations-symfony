<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * The swap point of the brand (F6, docs/brand.md): the files of `public/brand/`, the places that name them, and the few
 * colours that must repeat as literals outside Sass. A new logo, icon set or palette runs this test, and what it fails
 * is the list of what was not swapped.
 */
final class BrandFilesTest extends TestCase
{
    /** file => [width, height] of the PNGs the head, the manifest, the mails and the Open Graph tags name. */
    private const array PNG_SIZES = [
        'apple-touch-icon.png' => [180, 180],
        'icon-192.png' => [192, 192],
        'icon-512.png' => [512, 512],
        'icon-maskable-192.png' => [192, 192],
        'icon-maskable-512.png' => [512, 512],
        'og-default.png' => [1200, 630],
    ];

    /** The logo lockups (F6b): horizontal and stacked, for light and dark grounds, and the one-colour (print) variant. */
    private const array LOCKUPS = [
        'logo.svg', 'logo-dark.svg', 'logo-mono.svg', 'logo-stacked.svg', 'logo-stacked-dark.svg', 'logo-stacked-mono.svg',
    ];

    public function test_every_brand_file_the_site_names_exists(): void
    {
        $named = [
            ...array_keys(self::PNG_SIZES), 'favicon.ico', 'favicon.svg', 'site.webmanifest', 'mail-logo.png', ...self::LOCKUPS,
        ];
        foreach ($named as $file) {
            self::assertFileExists($this->root().'/public/brand/'.$file);
        }

        $head = (string) file_get_contents($this->root().'/templates/layout/_brand_head.html.twig');
        preg_match_all("~asset\\('brand/([a-z0-9.-]+)'\\)~", $head, $matches);
        self::assertNotSame([], $matches[1]);
        foreach ($matches[1] as $file) {
            self::assertContains($file, $named, "{$file} is linked by the brand head.");
        }
    }

    #[DataProvider('pngs')]
    public function test_a_png_has_the_size_that_names_it(string $file, int $width, int $height): void
    {
        $header = (string) file_get_contents($this->root().'/public/brand/'.$file, false, null, 0, 24);

        self::assertSame("\x89PNG\r\n\x1a\n", substr($header, 0, 8), $file.' is a PNG.');
        self::assertSame([$width, $height], array_values(unpack('N2', substr($header, 16, 8)) ?: []), $file);
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function pngs(): iterable
    {
        foreach (self::PNG_SIZES as $file => [$width, $height]) {
            yield $file => [$file, $width, $height];
        }
    }

    public function test_the_mail_logo_is_the_png_the_layout_attaches_at_twice_its_size(): void
    {
        $layout = (string) file_get_contents($this->root().'/templates/emails/layout.html.twig');
        if (1 !== preg_match("~email\\.image\\('@brand/mail-logo\\.png', 'image/png'\\).*?width=\"(\\d+)\" height=\"(\\d+)\"~s", $layout, $m)) {
            self::fail('The mail layout attaches @brand/mail-logo.png with a width and a height.');
        }

        $header = (string) file_get_contents($this->root().'/public/brand/mail-logo.png', false, null, 0, 24);
        [$width, $height] = array_values(unpack('N2', substr($header, 16, 8)) ?: []);
        self::assertSame(2 * (int) $m[1], $width);
        self::assertSame(2 * (int) $m[2], $height);
    }

    public function test_the_favicon_ico_holds_the_16_32_and_48_px_images(): void
    {
        $ico = (string) file_get_contents($this->root().'/public/brand/favicon.ico');

        self::assertSame([0, 1, 3], array_values(unpack('v3', substr($ico, 0, 6)) ?: []), 'An ICO with three images.');
        $sizes = [];
        foreach ([0, 1, 2] as $i) {
            $entry = unpack('Cwidth/Cheight', substr($ico, 6 + 16 * $i, 2));
            \assert(\is_array($entry));
            $sizes[] = [$entry['width'], $entry['height']];
        }
        self::assertSame([[16, 16], [32, 32], [48, 48]], $sizes);
    }

    public function test_the_brand_doc_names_every_file_of_the_folder(): void
    {
        $doc = (string) file_get_contents($this->root().'/docs/brand.md');
        $files = glob($this->root().'/public/brand/*') ?: [];
        self::assertGreaterThanOrEqual(9, \count($files));

        foreach ($files as $file) {
            self::assertStringContainsString(basename($file), $doc, basename($file).' is not in docs/brand.md.');
        }
    }

    public function test_the_manifest_names_the_site_and_the_icons_that_exist(): void
    {
        $manifest = json_decode((string) file_get_contents($this->root().'/public/brand/site.webmanifest'), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        $tokens = $this->tokens();

        self::assertSame($this->siteName(), $manifest['name']);
        self::assertSame('#'.$tokens['surface'], $manifest['theme_color']);
        self::assertSame('#'.$tokens['surface'], $manifest['background_color']);
        self::assertIsArray($manifest['icons']);
        foreach ($manifest['icons'] as $icon) {
            self::assertIsArray($icon);
            self::assertStringStartsWith('/brand/', $icon['src']);
            self::assertFileExists($this->root().'/public'.$icon['src']);
            [$width, $height] = array_map('intval', explode('x', (string) $icon['sizes']));
            self::assertSame(self::PNG_SIZES[basename((string) $icon['src'])], [$width, $height]);
        }
    }

    public function test_the_theme_colour_of_the_head_is_the_page_ground(): void
    {
        $head = (string) file_get_contents($this->root().'/templates/layout/_brand_head.html.twig');

        if (1 !== preg_match('~<meta name="theme-color" content="(#[0-9a-f]{6})">~', $head, $m)) {
            self::fail('The brand head sets a theme colour.');
        }
        self::assertSame('#'.$this->tokens()['surface'], $m[1]);
    }

    public function test_the_standalone_svgs_use_colours_of_the_tokens_only(): void
    {
        // White (`surface-raised`, `on-accent`) and black (`ink-strong`, the mono lockups) are tokens written as `#fff` and `#000`.
        $allowed = [...array_values($this->tokens()), 'ffffff', '000000'];

        foreach (['favicon.svg', ...self::LOCKUPS] as $file) {
            $svg = (string) file_get_contents($this->root().'/public/brand/'.$file);
            self::assertStringContainsString('viewBox="', $svg, $file);
            preg_match_all('/#([0-9a-f]{6})\b/i', $svg, $matches);
            self::assertNotSame([], $matches[1], $file);
            foreach ($matches[1] as $hex) {
                self::assertContains(strtolower($hex), $allowed, "#{$hex} in {$file} is not a colour of the brand tokens.");
            }
        }
    }

    public function test_the_site_name_of_the_layout_strings_the_logo_and_the_manifest_agree(): void
    {
        $name = $this->siteName();
        $messages = Yaml::parseFile($this->root().'/translations/messages+intl-icu.de.yaml');
        self::assertIsArray($messages);
        $layout = $messages['layout'];

        self::assertSame($name, $layout['site_name']);
        self::assertSame($name, $layout['logo_alt']);
        self::assertStringContainsString($name, $layout['footer']['copyright']);
        self::assertStringContainsString($name, (string) Yaml::parseFile($this->root().'/config/packages/scheb_2fa.yaml')['scheb_two_factor']['totp']['issuer']);
    }

    public function test_no_trace_of_the_wedding_brand_is_left_in_the_front_end(): void
    {
        $files = Finder::create()->files()->in([
            $this->root().'/assets/styles', $this->root().'/assets/js', $this->root().'/assets/icons', $this->root().'/assets/fonts',
            $this->root().'/templates', $this->root().'/public/brand',
        ])->name(['*.scss', '*.js', '*.svg', '*.twig', '*.md', '*.txt', '*.webmanifest']);

        $seen = 0;
        foreach ($files as $file) {
            ++$seen;
            self::assertDoesNotMatchRegularExpression('/figtree|logo-hzl/i', $file->getContents(), $file->getPathname());
        }
        self::assertGreaterThan(50, $seen);
        self::assertSame([], glob($this->root().'/assets/images/*') ?: [], 'assets/images held the wedding logo and is gone.');
    }

    public function test_the_lockups_and_the_inline_logo_draw_the_mark_of_the_favicon(): void
    {
        $favicon = (string) file_get_contents($this->root().'/public/brand/favicon.svg');
        if (1 !== preg_match('/<g transform="(translate\(16 [^"]+)">/', $favicon, $m)) {
            self::fail('favicon.svg is the compact mark.');
        }
        preg_match_all('/ d="([^"]+)"/', $favicon, $paths);
        self::assertCount(2, $paths[1], 'favicon.svg draws the pin and the spark.');
        $mark = [$m[1], ...$paths[1]]; // the pin's transform, the pin's path, the spark's path

        $files = [...array_map(fn (string $file): string => $this->root().'/public/brand/'.$file, self::LOCKUPS), $this->root().'/templates/components/_logo.html.twig'];
        foreach ($files as $file) {
            $svg = (string) file_get_contents($file);
            foreach ($mark as $part) {
                self::assertStringContainsString($part, $svg, basename($file).' draws the mark of favicon.svg.');
            }
        }
    }

    public function test_the_maskable_and_apple_icons_are_opaque_and_named_for_their_purpose(): void
    {
        $manifest = json_decode((string) file_get_contents($this->root().'/public/brand/site.webmanifest'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['icons']);
        $opaque = ['apple-touch-icon.png'];
        foreach ($manifest['icons'] as $icon) {
            self::assertIsArray($icon);
            if ('maskable' === $icon['purpose']) {
                self::assertStringStartsWith('/brand/icon-maskable-', (string) $icon['src'], 'A maskable icon needs a full ground and the safe zone.');
                $opaque[] = basename((string) $icon['src']);
            }
        }
        self::assertCount(3, $opaque);

        foreach ($opaque as $file) {
            $image = imagecreatefrompng($this->root().'/public/brand/'.$file);
            self::assertNotFalse($image, $file);
            self::assertSame(0, imagecolorat($image, 0, 0) >> 24 & 0x7F, $file.' has an opaque corner (no transparency).');
        }
    }

    public function test_the_mono_lockups_have_one_colour_on_white(): void
    {
        foreach (['logo-mono.svg', 'logo-stacked-mono.svg'] as $file) {
            preg_match_all('/#([0-9a-f]{6})\b/i', (string) file_get_contents($this->root().'/public/brand/'.$file), $matches);
            $colours = array_unique(array_map('strtolower', $matches[1]));
            sort($colours);
            self::assertSame(['000000', 'ffffff'], $colours, $file.': black, and white for the pin.');
        }
    }

    private function siteName(): string
    {
        $services = Yaml::parseFile($this->root().'/config/services.yaml');
        self::assertIsArray($services);

        return (string) $services['parameters']['site.name'];
    }

    /**
     * @return array<string, string> token name without `--c-` => hex without `#`
     */
    private function tokens(): array
    {
        $scss = (string) file_get_contents($this->root().'/assets/styles/abstracts/_tokens.scss');
        preg_match_all('/--c-([a-z0-9-]+):\s*#([0-9a-f]{6})\s*;/i', $scss, $matches, \PREG_SET_ORDER);

        $tokens = [];
        foreach ($matches as $match) {
            $tokens[$match[1]] = strtolower($match[2]);
        }
        self::assertArrayHasKey('surface', $tokens);

        return $tokens;
    }

    private function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
