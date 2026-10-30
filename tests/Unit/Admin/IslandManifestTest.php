<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Admin\Twig\IslandExtension;
use App\Admin\Twig\IslandManifest;
use PHPUnit\Framework\TestCase;

final class IslandManifestTest extends TestCase
{
    public function test_it_finds_the_entry(): void
    {
        self::assertSame('assets/main-123.js', self::manifest()->entryFile());
    }

    public function test_without_an_island_only_the_entry_imports_are_preloaded(): void
    {
        self::assertSame(['assets/vendor-abc.js'], self::manifest()->preloadFiles());
    }

    public function test_a_used_island_adds_its_chunk_and_recursive_imports_but_not_the_others(): void
    {
        $manifest = self::manifest();
        $manifest->markUsed('hello');

        self::assertSame(['assets/vendor-abc.js', 'assets/HelloIsland-456.js', 'assets/shared-def.js'], $manifest->preloadFiles());
    }

    public function test_a_kebab_case_name_finds_its_pascal_case_file(): void
    {
        $manifest = self::manifest();
        $manifest->markUsed('photo-manager');

        self::assertContains('assets/PhotoManagerIsland-789.js', $manifest->preloadFiles());
    }

    public function test_an_unknown_island_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unknown island "nope"');

        self::manifest()->markUsed('nope');
    }

    public function test_a_missing_manifest_says_what_to_run(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('run make admin-build');

        (new IslandManifest('/nonexistent/manifest.json'))->entryFile();
    }

    public function test_reset_forgets_the_used_islands(): void
    {
        $manifest = self::manifest();
        $manifest->markUsed('hello');

        $manifest->reset();

        self::assertFalse($manifest->anyUsed());
    }

    public function test_a_page_without_an_island_gets_no_assets_and_needs_no_build(): void
    {
        self::assertSame('', (new IslandExtension(new IslandManifest('/nonexistent/manifest.json')))->assets());
    }

    public function test_the_mount_point_carries_escaped_json_props(): void
    {
        $extension = new IslandExtension(self::manifest());

        self::assertSame(
            '<div data-island="hello" data-props="{&quot;greeting&quot;:&quot;Grüezi &lt;b&gt;&quot;}"></div>',
            $extension->island('hello', ['greeting' => 'Grüezi <b>']),
        );
    }

    public function test_empty_props_are_a_json_object(): void
    {
        self::assertStringContainsString('data-props="{}"', (new IslandExtension(self::manifest()))->island('hello'));
    }

    public function test_assets_list_the_preloads_and_the_entry_script(): void
    {
        $extension = new IslandExtension(self::manifest());
        $extension->island('hello');

        self::assertSame(
            '<link rel="modulepreload" href="/admin-build/assets/vendor-abc.js">'."\n"
            .'<link rel="modulepreload" href="/admin-build/assets/HelloIsland-456.js">'."\n"
            .'<link rel="modulepreload" href="/admin-build/assets/shared-def.js">'."\n"
            .'<script type="module" src="/admin-build/assets/main-123.js"></script>',
            $extension->assets(),
        );
    }

    public function test_a_nonce_goes_on_every_tag(): void
    {
        $extension = new IslandExtension(self::manifest());
        $extension->island('hello');

        self::assertSame(4, substr_count($extension->assets('abc'), ' nonce="abc"'));
    }

    private static function manifest(): IslandManifest
    {
        return new IslandManifest(\dirname(__DIR__, 2).'/Fixtures/Admin/manifest.json');
    }
}
