<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media;

use App\Media\Exception\InvalidMediaPath;
use App\Media\MediaPaths;
use App\Media\ValueObject\ImageFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MediaPathsTest extends TestCase
{
    private MediaPaths $paths;

    protected function setUp(): void
    {
        $this->paths = new MediaPaths('/srv/media/', '/app/public/media');
    }

    public function test_builds_the_s9_paths(): void
    {
        self::assertSame('/srv/media/demo/photo-01.jpg', $this->paths->original('demo/photo-01.jpg'));
        self::assertSame('/app/public/media/12', $this->paths->photoDirectory(12));
        self::assertSame('/app/public/media/12/abc-640.webp', $this->paths->variantFile(12, 'abc', 640, ImageFormat::Webp));
        self::assertSame('/app/public/media/12/doc.pdf', $this->paths->documentFile(12, 'doc'));
        self::assertSame('/media/12/doc.pdf', MediaPaths::documentUrl(12, 'doc'));
        self::assertSame('/srv/media/', $this->paths->mediaRoot());
        self::assertSame('/app/public/media', $this->paths->publicDirectory());
    }

    public function test_accepts_dots_inside_names(): void
    {
        self::assertSame('/srv/media/a/..b/c.d.jpg', $this->paths->original('a/..b/c.d.jpg'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'absolute' => ['/etc/passwd'];
        yield 'parent segment' => ['demo/../../etc/passwd'];
        yield 'leading parent' => ['../secret.pdf'];
        yield 'backslash' => ['demo\\photo.jpg'];
        yield 'nul byte' => ["demo/photo.jpg\0.pdf"];
    }

    #[DataProvider('invalid')]
    public function test_rejects_paths_outside_media_root(string $path): void
    {
        $this->expectException(InvalidMediaPath::class);

        $this->paths->original($path);
    }
}
