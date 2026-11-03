<?php

declare(strict_types=1);

namespace App\Crm;

/**
 * The raw eventlokale payloads under `<root>/eventlokale/` (#49): the crawl's resume checkpoint
 * and the CSV's only source. `index.json` holds the list items, `taxonomies.json` the term
 * groups, `venues/<id>.json` a detail as served and `venues/<id>.gone` a venue that answered
 * 404 or 410. Every file is written through a temporary name and rename(), so a killed run
 * never leaves half a file.
 */
final readonly class EventlokaleCache
{
    private const int JSON_FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES;

    public function __construct(
        private string $root,
    ) {
    }

    public function hasIndex(): bool
    {
        return is_file($this->path('index.json'));
    }

    /**
     * @return array{fetched_at: string, total: int, items: list<array<mixed>>}
     */
    public function readIndex(): array
    {
        $index = $this->readJson($this->path('index.json'));
        $items = \is_array($index['items'] ?? null) ? array_values(array_filter($index['items'], \is_array(...))) : [];

        return [
            'fetched_at' => \is_string($index['fetched_at'] ?? null) ? $index['fetched_at'] : '',
            'total' => \is_int($index['total'] ?? null) ? $index['total'] : 0,
            'items' => $items,
        ];
    }

    /**
     * @param list<array<mixed>> $items
     */
    public function writeIndex(array $items, int $total, \DateTimeImmutable $at): void
    {
        $this->writeJson($this->path('index.json'), ['fetched_at' => $at->format(\DATE_ATOM), 'total' => $total, 'items' => $items]);
    }

    public function hasTaxonomies(): bool
    {
        return is_file($this->path('taxonomies.json'));
    }

    /**
     * @return array<mixed>
     */
    public function readTaxonomies(): array
    {
        return $this->hasTaxonomies() ? $this->readJson($this->path('taxonomies.json')) : [];
    }

    /**
     * @param array<mixed> $taxonomies
     */
    public function writeTaxonomies(array $taxonomies): void
    {
        $this->writeJson($this->path('taxonomies.json'), $taxonomies);
    }

    /** True when the detail was fetched or the venue is known to be gone. */
    public function isSettled(int $id): bool
    {
        return is_file($this->detailPath($id)) || $this->isGone($id);
    }

    /**
     * @return array<mixed>|null
     */
    public function readDetail(int $id): ?array
    {
        return is_file($this->detailPath($id)) ? $this->readJson($this->detailPath($id)) : null;
    }

    public function isGone(int $id): bool
    {
        return is_file($this->gonePath($id));
    }

    /**
     * @param array<mixed> $detail
     */
    public function writeDetail(int $id, array $detail): void
    {
        $this->writeJson($this->detailPath($id), $detail);
        // A refresh may find a venue back that was gone.
        @unlink($this->gonePath($id));
    }

    public function markGone(int $id): void
    {
        $this->replace($this->gonePath($id), '');
        @unlink($this->detailPath($id));
    }

    public function detailMtime(int $id): ?int
    {
        $mtime = is_file($this->detailPath($id)) ? filemtime($this->detailPath($id)) : false;

        return false === $mtime ? null : $mtime;
    }

    /** Writes a file relative to the root, such as the CSV under owner/. */
    public function writeFile(string $relative, string $contents): void
    {
        $this->replace($this->root.'/'.$relative, $contents);
    }

    private function path(string $name): string
    {
        return $this->root.'/eventlokale/'.$name;
    }

    private function detailPath(int $id): string
    {
        return $this->path('venues/'.$id.'.json');
    }

    private function gonePath(int $id): string
    {
        return $this->path('venues/'.$id.'.gone');
    }

    /**
     * @return array<mixed>
     */
    private function readJson(string $file): array
    {
        $contents = file_get_contents($file);
        if (false === $contents) {
            throw new \RuntimeException(\sprintf('Cannot read "%s".', $file));
        }
        $decoded = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<mixed> $data
     */
    private function writeJson(string $file, array $data): void
    {
        $this->replace($file, json_encode($data, self::JSON_FLAGS));
    }

    /** The temp + rename of ContentYamlFile::replace(), kept here because that one is venue.yaml's. */
    private function replace(string $file, string $contents): void
    {
        $folder = \dirname($file);
        if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
            throw new \RuntimeException(\sprintf('Cannot create "%s".', $folder));
        }
        $temporary = $folder.'/.'.basename($file).'.'.bin2hex(random_bytes(4));
        if (false === file_put_contents($temporary, $contents) || !rename($temporary, $file)) {
            @unlink($temporary);

            throw new \RuntimeException(\sprintf('Cannot write "%s".', $file));
        }
    }
}
