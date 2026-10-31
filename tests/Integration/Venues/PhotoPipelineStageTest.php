<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Media\Exception\UnreadableImage;
use App\Media\MediaPaths;
use App\Tests\Support\Media\MediaFiles;
use App\Tests\Support\Media\TestImages;
use App\Venues\PhotoPipeline;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * PhotoPipeline::stage() when rendering fails (#25, D6): an original stored by this call is
 * removed again, one that existed before stays.
 */
final class PhotoPipelineStageTest extends KernelTestCase
{
    private MediaPaths $paths;
    private PhotoPipeline $photos;
    private string $folder;
    private string $sources;
    private string $staging;

    protected function setUp(): void
    {
        $paths = self::getContainer()->get(MediaPaths::class);
        $photos = self::getContainer()->get(PhotoPipeline::class);
        self::assertInstanceOf(MediaPaths::class, $paths);
        self::assertInstanceOf(PhotoPipeline::class, $photos);
        $this->paths = $paths;
        $this->photos = $photos;
        $this->folder = 'test-stage-'.uniqid();
        $this->sources = \dirname($paths->publicDirectory()).'/sources-'.uniqid();
        $this->staging = $photos->openStaging();
    }

    protected function tearDown(): void
    {
        $this->photos->discardStaging($this->staging);
        MediaFiles::removeDirectory($this->sources);
        MediaFiles::removeDirectory($this->paths->original($this->folder));
        parent::tearDown();
    }

    public function test_a_corrupt_body_leaves_no_original(): void
    {
        $source = TestImages::write($this->sources, 'kaputt.png', TestImages::pngHeader(200, 100));

        try {
            $this->photos->stage($source, $this->folder.'/kaputt.png', $this->staging.'/0');
            self::fail('A corrupt body should not render.');
        } catch (UnreadableImage) {
        }

        self::assertFileDoesNotExist($this->paths->original($this->folder.'/kaputt.png'));
        self::assertSame([], glob($this->paths->original($this->folder).'/*') ?: []);
    }

    public function test_the_original_is_stored_only_after_the_render(): void
    {
        // A memory fatal runs no catch, so the order is what keeps an original from being orphaned: with a file
        // where the original's folder belongs the store fails, and the variants prove the render came first.
        $source = TestImages::write($this->sources, 'a.jpg', TestImages::jpeg(800, 400));
        TestImages::write(\dirname($this->paths->original($this->folder.'/x')), $this->folder, 'in the way');

        try {
            $this->photos->stage($source, $this->folder.'/a.jpg', $this->staging.'/0');
            self::fail('The store cannot succeed.');
        } catch (\RuntimeException) {
            self::assertNotSame([], glob($this->staging.'/0/v-*') ?: []);
        } finally {
            @unlink($this->paths->original($this->folder));
        }
    }

    public function test_an_original_that_existed_before_stays(): void
    {
        $source = TestImages::write($this->sources, 'kaputt.png', TestImages::pngHeader(200, 100));
        $existing = TestImages::write($this->paths->original($this->folder), 'kaputt.png', 'bytes of other rows');

        try {
            $this->photos->stage($source, $this->folder.'/kaputt.png', $this->staging.'/0');
            self::fail('A corrupt body should not render.');
        } catch (UnreadableImage) {
        }

        self::assertFileExists($existing);
        self::assertSame('bytes of other rows', file_get_contents($existing));
    }
}
