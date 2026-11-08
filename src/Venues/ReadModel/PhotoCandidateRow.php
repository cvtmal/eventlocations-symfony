<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

/**
 * One photo the website import found (G3) and nobody decided on yet, shown to the venue's claimant only (G6). The
 * URLs come from a stranger's website: link them, never embed or fetch them outside `SafeFetcher`.
 */
final readonly class PhotoCandidateRow
{
    public function __construct(
        public string $id,
        public string $imageUrl,
        public string $pageUrl,
        public ?string $alt,
        public ?int $width,
        public ?int $height,
    ) {
    }

    /**
     * The file name, for the list: the last path segment, decoded, at most 80 characters.
     */
    public function fileName(): string
    {
        $path = parse_url($this->imageUrl, \PHP_URL_PATH);
        $name = \is_string($path) ? rawurldecode(basename($path)) : '';
        $name = mb_check_encoding($name, 'UTF-8') ? $name : '';

        return '' === $name ? $this->imageUrl : mb_substr($name, 0, 80);
    }
}
