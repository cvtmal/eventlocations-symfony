<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Every custom property the public Sass reads is declared somewhere in it (#35). Sass does not catch an undefined
 * `var()`: the declaration is dropped at computed time and the page silently loses a colour or a gap. Reads the source;
 * `admin/`, `cms/`, `crm/` and `admin.scss` read the back office's `--a-*` tokens, which only `admin/` and `admin.scss`
 * declare.
 */
final class PublicTokensDefinedTest extends TestCase
{
    public function test_every_var_in_public_sass_is_declared(): void
    {
        $declared = self::declared();
        $undefined = [];

        foreach (self::sources() as $path => $source) {
            preg_match_all('/var\(\s*(--[a-z0-9-]+)\s*[,)]/', $source, $matches);
            foreach (array_diff($matches[1], $declared) as $name) {
                $undefined[] = "{$path}: {$name}";
            }
        }

        self::assertContains('--c-ink', $declared);
        self::assertSame([], array_values(array_unique($undefined)));
    }

    /**
     * The styleguide builds `var(--c-#{$token})` and `var(--space-#{$step})` from two lists, which the regex above
     * cannot read.
     */
    public function test_the_styleguides_interpolated_tokens_are_declared(): void
    {
        $source = self::sources()['styleguide.scss'];
        if (1 !== preg_match('/\$color-tokens:(.+?);/s', $source, $colors) || 1 !== preg_match('/@each \$step in (.+?) \{/', $source, $steps)) {
            self::fail('styleguide.scss no longer holds the $color-tokens list or the @each $step list.');
        }

        preg_match_all("/'([a-z0-9-]+)'/", $colors[1], $names);

        $expected = array_merge(
            array_map(static fn (string $name): string => '--c-'.$name, $names[1]),
            array_map(static fn (string $step): string => '--space-'.trim($step), explode(',', $steps[1])),
        );

        self::assertNotEmpty($names[1]);
        self::assertSame([], array_values(array_diff($expected, self::declared())));
    }

    /**
     * @return list<string>
     */
    private static function declared(): array
    {
        $declared = [];
        foreach (self::sources() as $source) {
            // Anchored, so `.btn--primary:hover` does not read as a declaration of `--primary`.
            preg_match_all('/(?:^|[{;])\s*(--[a-z0-9-]+)\s*:/m', $source, $matches);
            array_push($declared, ...$matches[1]);
        }

        return array_values(array_unique($declared));
    }

    /**
     * @return array<string, string> path below assets/styles => source
     */
    private static function sources(): array
    {
        $finder = (new Finder())
            ->files()
            ->in(\dirname(__DIR__, 3).'/assets/styles')
            ->name('*.scss')
            ->notName('admin.scss')
            ->exclude(['admin', 'cms', 'crm']);

        $sources = [];
        foreach ($finder as $file) {
            $sources[$file->getRelativePathname()] = $file->getContents();
        }

        return $sources;
    }
}
