<?php

declare(strict_types=1);

namespace App\Venues;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads the owner's Wix photos into a cache directory, one request at a time with a pause
 * after each, so a rerun of app:owner:export-content asks Wix only for what it lacks (#32 D13).
 * `/media/<id>` answers the original file.
 *
 * A file is complete or absent: the body goes to `<id>.part`, is checked against its
 * Content-Length (gd decodes a truncated JPEG without complaint) and only then renamed. The
 * id comes from a CSV cell, so it is checked before it becomes part of a URL or a path.
 */
final class WixPhotoCache
{
    public const string ID_PATTERN = '/^[A-Za-z0-9_~-]+\.(jpe?g|png|webp|avif)$/iD';

    private const float PAUSE_SECONDS = 0.25;
    private const int HTTP_OK = 200;

    public function __construct(
        private readonly HttpClientInterface $wixClient,
        private readonly ClockInterface $clock,
    ) {
    }

    public static function isId(string $id): bool
    {
        return 1 === preg_match(self::ID_PATTERN, $id);
    }

    /**
     * @throws \InvalidArgumentException for an id outside ID_PATTERN
     */
    public function path(string $cacheDir, string $id): string
    {
        if (!self::isId($id)) {
            throw new \InvalidArgumentException('Not a Wix media id.');
        }

        return rtrim($cacheDir, '/').'/'.$id;
    }

    public function has(string $cacheDir, string $id): bool
    {
        return is_file($this->path($cacheDir, $id));
    }

    /**
     * Makes sure the photo is in the cache: true when it is, false when the download failed
     * (transport error, a status other than 200, a short body), with nothing left behind.
     *
     * @throws \InvalidArgumentException for an id outside ID_PATTERN, before any request
     */
    public function fetch(string $cacheDir, string $id): bool
    {
        $file = $this->path($cacheDir, $id);
        if (is_file($file)) {
            return true;
        }
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            throw new \RuntimeException(\sprintf('Cannot create "%s".', $cacheDir));
        }

        $part = $file.'.part';
        try {
            if (!$this->download('/media/'.rawurlencode($id), $part) || !rename($part, $file)) {
                @unlink($part);

                return false;
            }
        } finally {
            $this->clock->sleep(self::PAUSE_SECONDS);
        }

        return true;
    }

    private function download(string $url, string $part): bool
    {
        $handle = fopen($part, 'w');
        if (false === $handle) {
            return false;
        }

        try {
            $response = $this->wixClient->request('GET', $url);
            if (self::HTTP_OK !== $response->getStatusCode()) {
                $response->cancel();

                return false;
            }
            $written = 0;
            foreach ($this->wixClient->stream($response) as $chunk) {
                $written += (int) fwrite($handle, $chunk->getContent());
            }
            $length = $response->getHeaders(false)['content-length'][0] ?? null;

            return $written > 0 && (null === $length || (int) $length === $written);
        } catch (TransportExceptionInterface) {
            return false;
        } finally {
            fclose($handle);
        }
    }
}
