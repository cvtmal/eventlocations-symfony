<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use PHPUnit\Framework\TestCase;

/**
 * ADM-8 on the real build (a prerequisite of `make test`): shared admin JS ≤ 60 KB gzip, each island chunk
 * ≤ 30 KB gzip, admin CSS ≤ 30 KB gzip.
 */
final class AdminAssetBudgetTest extends TestCase
{
    public function test_shared_javascript_is_within_60_kb_gzip(): void
    {
        $manifest = self::manifest();
        $keys = [];
        $walk = static function (string $key) use (&$walk, &$keys, $manifest): void {
            if (isset($keys[$key])) {
                return;
            }
            $keys[$key] = true;
            foreach ($manifest[$key]['imports'] ?? [] as $import) {
                $walk($import);
            }
        };
        $walk('src/main.js');

        $total = self::gzipSize(self::root().'/assets/js/admin/toast.js');
        foreach (array_keys($keys) as $key) {
            $total += self::gzipSize(self::root().'/public/admin-build/'.$manifest[$key]['file']);
        }

        self::assertLessThanOrEqual(60 * 1024, $total);
    }

    public function test_each_island_chunk_is_within_30_kb_gzip(): void
    {
        $islands = 0;
        foreach (self::manifest() as $key => $chunk) {
            if ($chunk['isDynamicEntry'] ?? false) {
                self::assertLessThanOrEqual(30 * 1024, self::gzipSize(self::root().'/public/admin-build/'.$chunk['file']), $key);
                ++$islands;
            }
        }

        self::assertGreaterThan(0, $islands);
    }

    public function test_admin_css_is_within_30_kb_gzip(): void
    {
        $built = glob(self::root().'/var/sass/admin-*.output.css') ?: [];
        if (1 !== \count($built)) {
            self::fail('Run make sass first (expected exactly one var/sass/admin-*.output.css).');
        }

        self::assertLessThanOrEqual(30 * 1024, self::gzipSize($built[0]));
    }

    /**
     * @return array<string, array{file: string, imports?: list<string>, isDynamicEntry?: bool}>
     */
    private static function manifest(): array
    {
        $path = self::root().'/public/admin-build/.vite/manifest.json';
        if (!is_file($path)) {
            self::fail('Run make admin-build first (no public/admin-build/.vite/manifest.json).');
        }

        /** @var array<string, array{file: string, imports?: list<string>, isDynamicEntry?: bool}> $manifest */
        $manifest = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);

        return $manifest;
    }

    private static function gzipSize(string $file): int
    {
        return \strlen((string) gzencode((string) file_get_contents($file), 9));
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
