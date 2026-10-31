<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Media\MediaPaths;
use App\Tests\Support\Media\MediaFiles;
use App\Tests\Support\Media\TestImages;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * JSON requests to #25's photo and room endpoints with the session's CSRF token, and the media files
 * they leave (originals under `MEDIA_ROOT/cms`, variants under the public directory), removed after
 * each test. Use with AdminSession.
 */
trait SendsVenueMediaRequests
{
    private ?string $uploads = null;
    private int $uploadCount = 0;

    private function removeVenueMedia(): void
    {
        $paths = self::getContainer()->get(MediaPaths::class);
        self::assertInstanceOf(MediaPaths::class, $paths);
        if (null !== $this->uploads) {
            MediaFiles::removeDirectory($this->uploads);
        }
        MediaFiles::removeDirectory($paths->original('cms'));
        MediaFiles::removeDirectory($paths->publicDirectory());
    }

    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', '/admin');

        return (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
    }

    /**
     * @param array<mixed>|string $body
     */
    private function json(string $method, string $url, array|string $body = [], ?string $token = null): void
    {
        $this->client->request($method, $url, server: array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ]), content: \is_string($body) ? $body : (string) json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, string> $fields
     */
    private function upload(string $url, ?UploadedFile $file, array $fields = [], ?string $token = null): void
    {
        $this->client->request('POST', $url, $fields, null === $file ? [] : ['file' => $file], array_filter([
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ]));
    }

    /**
     * A JPEG with other bytes on every call, as PHP leaves it in its temporary directory.
     */
    private function photoFile(string $bytes = '', string $name = 'foto.jpg', string $mime = 'image/jpeg'): UploadedFile
    {
        if ('' === $bytes) {
            $shade = 0x2F6B9D * ++$this->uploadCount;
            $bytes = TestImages::uniformJpeg(480, 320, ($shade >> 16) & 0xFF, ($shade >> 8) & 0xFF, $shade & 0xFF);
        }
        $this->uploads ??= sys_get_temp_dir().'/cms-media-api-'.bin2hex(random_bytes(4));

        // PHP names its temporary file itself (`/tmp/php…`), never after the client's name.
        return new UploadedFile(TestImages::write($this->uploads, uniqid('php'), $bytes), $name, $mime, null, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);

        /* @var array<string, mixed> $answer */
        return $answer;
    }

    /**
     * @return array<string, list<string>>
     */
    private function errors(): array
    {
        $errors = $this->answer()['errors'] ?? null;
        self::assertIsArray($errors);

        /* @var array<string, list<string>> $errors */
        return $errors;
    }
}
