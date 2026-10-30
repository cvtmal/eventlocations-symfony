<?php

declare(strict_types=1);

namespace App\Media;

use App\Media\Exception\InvalidMediaPath;
use App\Media\ValueObject\ImageFormat;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where media lives (S9): originals under MEDIA_ROOT (`app.media_root`), generated variants
 * and published PDFs under `app.media_public_dir` (public/media; var/test-media/public in
 * test), served by nginx as `/media/<venue-number>/…`.
 */
final class MediaPaths
{
    public const string URL_PREFIX = '/media';

    public function __construct(
        #[Autowire('%app.media_root%')]
        private readonly string $mediaRoot,
        #[Autowire('%app.media_public_dir%')]
        private readonly string $publicDirectory,
    ) {
    }

    /**
     * The absolute path of an original stored as `venue_photos.original_path` or `venue_documents.path`.
     *
     * @throws InvalidMediaPath for an empty or absolute path, a ".." segment, a backslash or a NUL byte
     */
    public function original(string $relativePath): string
    {
        if ('' === $relativePath
            || str_starts_with($relativePath, '/')
            || str_contains($relativePath, '\\')
            || str_contains($relativePath, "\0")
            || \in_array('..', explode('/', $relativePath), true)
        ) {
            throw InvalidMediaPath::forPath($relativePath);
        }

        return rtrim($this->mediaRoot, '/').'/'.$relativePath;
    }

    public function photoDirectory(int $venueNumber): string
    {
        return \sprintf('%s/%d', rtrim($this->publicDirectory, '/'), $venueNumber);
    }

    public function variantFile(int $venueNumber, string $photoId, int $width, ImageFormat $format): string
    {
        return \sprintf('%s/%s-%d.%s', $this->photoDirectory($venueNumber), $photoId, $width, $format->value);
    }

    public function documentFile(int $venueNumber, string $documentId): string
    {
        return \sprintf('%s/%s.pdf', $this->photoDirectory($venueNumber), $documentId);
    }

    public static function documentUrl(int $venueNumber, string $documentId): string
    {
        return \sprintf('%s/%d/%s.pdf', self::URL_PREFIX, $venueNumber, $documentId);
    }

    public function mediaRoot(): string
    {
        return $this->mediaRoot;
    }

    public function publicDirectory(): string
    {
        return $this->publicDirectory;
    }
}
