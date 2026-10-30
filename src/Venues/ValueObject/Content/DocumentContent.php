<?php

declare(strict_types=1);

namespace App\Venues\ValueObject\Content;

/**
 * One PDF of venue.yaml, with the file's hash and size.
 */
final readonly class DocumentContent
{
    /**
     * @param string                $file  the name in documents/
     * @param array<string, string> $title title by locale
     */
    public function __construct(
        public string $file,
        public string $absolutePath,
        public string $sha256,
        public int $sizeBytes,
        public array $title,
    ) {
    }
}
