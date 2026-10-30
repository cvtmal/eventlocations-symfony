<?php

declare(strict_types=1);

namespace App\Admin\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Reads Vite's manifest and remembers which islands the current page rendered, so a page loads no island
 * it does not use (ADM-8). An island `hello` is the file src/islands/HelloIsland.vue (admin-ui/src/islands.js).
 */
final class IslandManifest implements ResetInterface
{
    private const string ENTRY = 'src/main.js';

    /** @var array<string, array{file: string, imports?: list<string>}>|null */
    private ?array $manifest = null;

    /** @var array<string, true> */
    private array $used = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/public/admin-build/.vite/manifest.json')]
        private readonly string $path,
    ) {
    }

    public function reset(): void
    {
        $this->manifest = null;
        $this->used = [];
    }

    public function markUsed(string $island): void
    {
        $this->chunkKey($island);
        $this->used[$island] = true;
    }

    public function anyUsed(): bool
    {
        return [] !== $this->used;
    }

    /**
     * The entry's file, relative to public/admin-build/.
     */
    public function entryFile(): string
    {
        return $this->chunk(self::ENTRY)['file'];
    }

    /**
     * What the page should preload: the entry's static imports, then each used island with its imports.
     *
     * @return list<string> files relative to public/admin-build/
     */
    public function preloadFiles(): array
    {
        $keys = $this->staticImports(self::ENTRY);
        foreach (array_keys($this->used) as $island) {
            $key = $this->chunkKey($island);
            $keys = [...$keys, $key, ...$this->staticImports($key)];
        }

        $files = [];
        foreach ($keys as $key) {
            if (self::ENTRY !== $key) {
                $files[$this->chunk($key)['file']] = true;
            }
        }

        return array_keys($files);
    }

    /**
     * @return list<string> manifest keys, depth first
     */
    private function staticImports(string $key): array
    {
        $found = [];
        $walk = function (string $key) use (&$found, &$walk): void {
            foreach ($this->chunk($key)['imports'] ?? [] as $import) {
                if (!isset($found[$import])) {
                    $found[$import] = true;
                    $walk($import);
                }
            }
        };
        $walk($key);

        return array_keys($found);
    }

    private function chunkKey(string $island): string
    {
        $key = 'src/islands/'.str_replace(' ', '', ucwords(str_replace('-', ' ', $island))).'Island.vue';
        if (!isset($this->manifest()[$key])) {
            throw new \LogicException(\sprintf('Unknown island "%s": no %s in the manifest.', $island, $key));
        }

        return $key;
    }

    /**
     * @return array{file: string, imports?: list<string>}
     */
    private function chunk(string $key): array
    {
        return $this->manifest()[$key] ?? throw new \LogicException(\sprintf('The Vite manifest has no "%s".', $key));
    }

    /**
     * @return array<string, array{file: string, imports?: list<string>}>
     */
    private function manifest(): array
    {
        if (null === $this->manifest) {
            if (!is_file($this->path)) {
                throw new \RuntimeException('Admin UI not built: run make admin-build.');
            }

            /** @var array<string, array{file: string, imports?: list<string>}> $manifest */
            $manifest = json_decode((string) file_get_contents($this->path), true, flags: \JSON_THROW_ON_ERROR);
            $this->manifest = $manifest;
        }

        return $this->manifest;
    }
}
