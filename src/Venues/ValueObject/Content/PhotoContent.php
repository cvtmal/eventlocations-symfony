<?php

declare(strict_types=1);

namespace App\Venues\ValueObject\Content;

/**
 * One photo of venue.yaml (gallery or room), with the file's hash.
 */
final readonly class PhotoContent
{
    /**
     * @param string                $file         the name in the folder (photos/ or rooms/<key>/photos/)
     * @param string                $absolutePath the file on disk
     * @param string                $extension    lower case, jpeg as jpg
     * @param array<string, string> $alt          alt text by locale
     */
    public function __construct(
        public string $file,
        public string $absolutePath,
        public string $sha256,
        public string $extension,
        public array $alt,
    ) {
    }
}
