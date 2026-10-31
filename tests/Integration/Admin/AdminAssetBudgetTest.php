<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * ADM-8 on the real build (a prerequisite of `make test`): shared admin JS ≤ 60 KB gzip, each island with
 * its imports ≤ 30 KB gzip, admin CSS ≤ 30 KB gzip.
 */
#[Group('budget')]
final class AdminAssetBudgetTest extends TestCase
{
    private const string ENTRY = 'src/main.js';

    public function test_shared_javascript_is_within_60_kb_gzip(): void
    {
        $manifest = self::manifest();

        $total = 0;
        foreach (self::vanillaScripts() as $script) {
            $total += self::gzipSize($script);
        }
        foreach (self::closure($manifest, self::ENTRY) as $key) {
            $total += self::gzipSize(self::root().'/public/admin-build/'.$manifest[$key]['file']);
        }

        self::assertLessThanOrEqual(60 * 1024, $total);
    }

    /**
     * An island is measured with what it imports, minus what the entry already brings: a chunk shared by two
     * islands is no dynamic entry, so counting only the islands' own files would never see it.
     */
    public function test_each_island_is_within_30_kb_gzip(): void
    {
        $manifest = self::manifest();
        $shared = self::closure($manifest, self::ENTRY);
        $islands = 0;

        foreach ($manifest as $key => $chunk) {
            if ($chunk['isDynamicEntry'] ?? false) {
                $size = 0;
                foreach (array_diff(self::closure($manifest, $key), $shared) as $own) {
                    $size += self::gzipSize(self::root().'/public/admin-build/'.$manifest[$own]['file']);
                }

                self::assertLessThanOrEqual(30 * 1024, $size, $key);
                ++$islands;
            }
        }

        self::assertGreaterThan(0, $islands);
    }

    public function test_a_chunk_shared_between_islands_counts_for_each_of_them(): void
    {
        $manifest = [
            'src/main.js' => ['file' => 'main.js', 'imports' => ['_vue.js']],
            '_vue.js' => ['file' => 'vue.js'],
            '_shared.js' => ['file' => 'shared.js', 'imports' => ['src/main.js']],
            'src/islands/AIsland.vue' => ['file' => 'a.js', 'isDynamicEntry' => true, 'imports' => ['src/main.js', '_shared.js']],
            'src/islands/BIsland.vue' => ['file' => 'b.js', 'isDynamicEntry' => true, 'imports' => ['src/main.js', '_shared.js']],
        ];
        $shared = self::closure($manifest, 'src/main.js');

        self::assertSame(['src/main.js', '_vue.js'], $shared);
        self::assertSame(['src/islands/AIsland.vue', '_shared.js'], array_values(array_diff(self::closure($manifest, 'src/islands/AIsland.vue'), $shared)));
        self::assertSame(['src/islands/BIsland.vue', '_shared.js'], array_values(array_diff(self::closure($manifest, 'src/islands/BIsland.vue'), $shared)));
    }

    public function test_every_admin_script_is_loaded_by_the_layout(): void
    {
        $layout = (string) file_get_contents(self::root().'/templates/admin/base.html.twig')
            .(string) file_get_contents(self::root().'/templates/admin/components/_scripts.html.twig');

        foreach (self::vanillaScripts() as $script) {
            $asset = substr($script, \strlen(self::root().'/assets/'));
            self::assertStringContainsString("asset('".$asset."')", $layout);
        }
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
     * A chunk and all it imports, recursively.
     *
     * @param array<string, array{file: string, imports?: list<string>, isDynamicEntry?: bool}> $manifest
     *
     * @return list<string> manifest keys
     */
    private static function closure(array $manifest, string $key): array
    {
        $found = [];
        $walk = static function (string $key) use (&$walk, &$found, $manifest): void {
            if (isset($found[$key])) {
                return;
            }
            $found[$key] = true;
            foreach ($manifest[$key]['imports'] ?? [] as $import) {
                $walk($import);
            }
        };
        $walk($key);

        return array_keys($found);
    }

    /**
     * What the layout loads on every back-office page.
     *
     * @return list<string> absolute paths
     */
    private static function vanillaScripts(): array
    {
        return [...(glob(self::root().'/assets/js/admin/*.js') ?: []), self::root().'/assets/js/dialog.js'];
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
