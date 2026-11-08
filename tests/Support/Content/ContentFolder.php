<?php

declare(strict_types=1);

namespace App\Tests\Support\Content;

use App\Tests\Support\Media\MediaFiles;
use App\Tests\Support\Media\TestImages;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds fictional content folders (`<root>/venues/<key>/`, docs/content-format.md) at
 * runtime; the repo keeps no content fixtures. Every venue starts from a minimal valid
 * venue.yaml in Zürich (8001); photo() and document() write the file and list it.
 */
final class ContentFolder
{
    /** @var array<string, array<string, mixed>> venue.yaml data by key */
    private array $venues = [];

    public function __construct(
        private readonly string $root,
    ) {
    }

    /**
     * The minimal valid venue.yaml data.
     *
     * @return array<string, mixed>
     */
    public static function minimal(string $name = 'Gasthaus Beispiel'): array
    {
        return [
            'name' => $name,
            'capacity' => ['max' => 120],
            'address' => ['street' => 'Musterweg 1', 'zip' => '8001', 'town' => 'Zürich'],
            'geo' => ['lat' => 47.37, 'lng' => 8.54],
            'translations' => ['de' => ['summary' => 'Fiktive Testlocation am Stadtrand.']],
        ];
    }

    /**
     * Writes venues/<key>/venue.yaml from minimal() with $overrides merged in (maps merge,
     * lists are replaced, a null value removes the field).
     *
     * @param array<string, mixed> $overrides
     */
    public function venue(string $key, array $overrides = [], ?string $name = null): self
    {
        $this->venues[$key] = self::merge(self::minimal($name ?? 'Gasthaus '.ucfirst(str_replace('-', ' ', $key))), $overrides);

        return $this->write($key);
    }

    /**
     * Changes the venue.yaml data of an existing venue folder.
     *
     * @param array<string, mixed> $overrides merged as in venue()
     */
    public function change(string $key, array $overrides): self
    {
        $this->venues[$key] = self::merge($this->venues[$key], $overrides);

        return $this->write($key);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $edit
     */
    public function edit(string $key, callable $edit): self
    {
        $this->venues[$key] = $edit($this->venues[$key]);

        return $this->write($key);
    }

    /**
     * Writes an image into photos/ (or rooms/<room>/photos/) and appends it to that list.
     *
     * @param array<string, string> $alt
     */
    public function photo(string $key, string $file, ?string $room = null, ?string $bytes = null, array $alt = []): self
    {
        $directory = null === $room ? 'photos' : 'rooms/'.$room.'/photos';
        $this->file($key, $directory.'/'.$file, $bytes ?? TestImages::jpeg(800, 400));
        $entry = ['file' => $file] + ([] === $alt ? [] : ['alt' => $alt]);

        if (null === $room) {
            $this->venues[$key]['photos'] = [...$this->listAt($this->venues[$key], 'photos'), $entry];
        } else {
            $rooms = $this->listAt($this->venues[$key], 'rooms');
            foreach ($rooms as $i => $candidate) {
                \assert(\is_array($candidate));
                if ($candidate['key'] === $room) {
                    $rooms[$i]['photos'] = [...$this->listAt($candidate, 'photos'), $entry];
                }
            }
            $this->venues[$key]['rooms'] = $rooms;
        }

        return $this->write($key);
    }

    /**
     * Writes a one-page PDF into documents/ and appends it to the documents list.
     */
    public function document(string $key, string $file, string $title = 'Preisliste', string $text = 'Fiktive Preisliste'): self
    {
        $this->file($key, 'documents/'.$file, self::pdf($text));
        $this->venues[$key]['documents'] = [...$this->listAt($this->venues[$key], 'documents'), ['file' => $file, 'title' => ['de' => $title]]];

        return $this->write($key);
    }

    /**
     * Writes any file into the venue folder without listing it.
     */
    public function file(string $key, string $relativePath, string $bytes): self
    {
        TestImages::write(\dirname($this->folder($key).'/'.$relativePath), basename($relativePath), $bytes);

        return $this;
    }

    /**
     * Writes venue.yaml as raw text (e.g. broken YAML).
     */
    public function rawYaml(string $key, string $yaml): self
    {
        return $this->file($key, 'venue.yaml', $yaml);
    }

    public function remove(string $key): self
    {
        MediaFiles::removeDirectory($this->folder($key));
        unset($this->venues[$key]);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(string $key): array
    {
        return $this->venues[$key];
    }

    public function path(): string
    {
        return $this->root;
    }

    public function folder(string $key): string
    {
        return $this->root.'/venues/'.$key;
    }

    public function removeAll(): void
    {
        MediaFiles::removeDirectory($this->root);
    }

    /**
     * A minimal valid one-page PDF (as DemoMedia writes them).
     */
    public static function pdf(string $text = 'Fiktiv'): string
    {
        $content = \sprintf("BT /F1 18 Tf 72 760 Td (%s) Tj ET\n", $text);
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            \sprintf("<< /Length %d >>\nstream\n%sendstream", \strlen($content), $content),
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = \strlen($pdf);
            $pdf .= \sprintf("%d 0 obj\n%s\nendobj\n", $index + 1, $object);
        }
        $xref = \strlen($pdf);
        $pdf .= \sprintf("xref\n0 %d\n0000000000 65535 f \n", \count($objects) + 1);
        foreach ($offsets as $offset) {
            $pdf .= \sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.\sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", \count($objects) + 1, $xref);
    }

    private function write(string $key): self
    {
        return $this->file($key, 'venue.yaml', Yaml::dump($this->venues[$key], 10, 2));
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<mixed>
     */
    private function listAt(array $data, string $field): array
    {
        $list = $data[$field] ?? [];
        \assert(\is_array($list));

        return array_values($list);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $field => $value) {
            if (null === $value) {
                unset($base[$field]);
            } elseif (\is_array($value) && !array_is_list($value) && \is_array($base[$field] ?? null) && !array_is_list($base[$field])) {
                /** @var array<string, mixed> $nested */
                $nested = $base[$field];
                /* @var array<string, mixed> $value */
                $base[$field] = self::merge($nested, $value);
            } else {
                $base[$field] = $value;
            }
        }

        return $base;
    }
}
