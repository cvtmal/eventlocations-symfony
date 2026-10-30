<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media;

use App\Media\Exception\InvalidMediaPath;
use App\Media\Exception\MissingOriginal;
use App\Media\ImageVariantGenerator;
use App\Media\MediaPaths;
use App\Media\MediaPublisher;
use App\Media\ValueObject\ImageFormat;
use App\Tests\Support\Media\MediaFiles;
use App\Tests\Support\Media\TestImages;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Publishing into the test public dir (var/test-media/public): variants under the photo id,
 * PDFs under the document id, a venue's directory removed.
 */
final class MediaPublisherTest extends KernelTestCase
{
    private const int VENUE = 990001;

    private MediaPaths $paths;
    private MediaPublisher $publisher;
    private string $staging;
    private string $originals;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $paths = $container->get(MediaPaths::class);
        $publisher = $container->get(MediaPublisher::class);
        self::assertInstanceOf(MediaPaths::class, $paths);
        self::assertInstanceOf(MediaPublisher::class, $publisher);
        $this->paths = $paths;
        $this->publisher = $publisher;
        self::assertStringEndsWith('/var/test-media/public', $paths->publicDirectory());
        self::assertStringEndsWith('/var/test-media/originals', $paths->mediaRoot());
        $this->staging = \dirname($paths->publicDirectory()).'/tmp-'.uniqid();
        $this->originals = $paths->mediaRoot().'/test-publisher-'.uniqid();
    }

    protected function tearDown(): void
    {
        MediaFiles::removeDirectory($this->staging);
        MediaFiles::removeDirectory($this->originals);
        $this->publisher->removeVenue(self::VENUE);
        parent::tearDown();
    }

    public function test_copies_every_variant_under_the_photo_id(): void
    {
        $source = TestImages::write($this->staging, 'in.jpg', TestImages::jpeg(1000, 600));
        $image = (new ImageVariantGenerator())->generate($source, $this->staging, 'v');

        $this->publisher->copyVariants($image, $this->staging, 'v', self::VENUE, 'photo-id');

        $files = array_map('basename', glob($this->paths->photoDirectory(self::VENUE).'/*') ?: []);
        sort($files);
        $expected = [];
        foreach ([360, 640, 960] as $width) {
            foreach (ImageFormat::cases() as $format) {
                $expected[] = \sprintf('photo-id-%d.%s', $width, $format->value);
                self::assertFileEquals(
                    \sprintf('%s/v-%d.%s', $this->staging, $width, $format->value),
                    $this->paths->variantFile(self::VENUE, 'photo-id', $width, $format),
                );
            }
        }
        sort($expected);
        self::assertSame($expected, $files);
    }

    public function test_publishes_a_document_and_returns_its_size(): void
    {
        TestImages::write($this->originals, 'preise.pdf', "%PDF-1.4\n%%EOF\n");
        $relative = basename($this->originals).'/preise.pdf';

        $size = $this->publisher->publishDocument($relative, self::VENUE, 'doc-id');

        self::assertSame(15, $size);
        self::assertStringEqualsFile($this->paths->documentFile(self::VENUE, 'doc-id'), "%PDF-1.4\n%%EOF\n");
    }

    public function test_a_missing_document_is_reported(): void
    {
        $this->expectException(MissingOriginal::class);

        $this->publisher->publishDocument('test-publisher-missing/nope.pdf', self::VENUE, 'doc-id');
    }

    public function test_removes_a_venue_directory_and_tolerates_a_missing_one(): void
    {
        TestImages::write($this->paths->photoDirectory(self::VENUE), 'x-360.jpg', 'x');

        $this->publisher->removeVenue(self::VENUE);
        self::assertDirectoryDoesNotExist($this->paths->photoDirectory(self::VENUE));

        $this->publisher->removeVenue(self::VENUE);
        self::assertDirectoryDoesNotExist($this->paths->photoDirectory(self::VENUE));
    }

    public function test_stores_an_original_once(): void
    {
        $source = TestImages::write($this->staging, 'in.jpg', TestImages::jpeg(100, 50));
        $relative = basename($this->originals).'/ab/c.jpg';
        $target = $this->paths->original($relative);

        self::assertTrue($this->publisher->storeOriginal($source, $relative));
        self::assertFileEquals($source, $target);
        touch($target, 1_000_000_000);
        clearstatcache();

        self::assertFalse($this->publisher->storeOriginal($source, $relative));
        clearstatcache();
        self::assertSame(1_000_000_000, filemtime($target));
        self::assertFileDoesNotExist($target.'.tmp');
    }

    public function test_store_original_rejects_a_path_outside_media_root(): void
    {
        $source = TestImages::write($this->staging, 'in.jpg', 'x');

        $this->expectException(InvalidMediaPath::class);

        $this->publisher->storeOriginal($source, '../x.jpg');
    }

    public function test_removes_one_photo_and_keeps_the_others(): void
    {
        $directory = $this->paths->photoDirectory(self::VENUE);
        foreach (['a-360.jpg', 'a-640.webp', 'a-360.avif', 'b-360.jpg', 'b-640.avif', 'doc.pdf'] as $file) {
            TestImages::write($directory, $file, 'x');
        }

        $this->publisher->removePhoto(self::VENUE, 'a');

        $files = array_map('basename', glob($directory.'/*') ?: []);
        sort($files);
        self::assertSame(['b-360.jpg', 'b-640.avif', 'doc.pdf'], $files);
    }

    public function test_removes_a_document_and_an_original_and_tolerates_missing_ones(): void
    {
        TestImages::write($this->paths->photoDirectory(self::VENUE), 'doc-id.pdf', '%PDF-');
        TestImages::write($this->originals, 'o.pdf', '%PDF-');
        $relative = basename($this->originals).'/o.pdf';

        $this->publisher->removeDocument(self::VENUE, 'doc-id');
        $this->publisher->removeOriginal($relative);
        $this->publisher->removeDocument(self::VENUE, 'doc-id');
        $this->publisher->removeOriginal($relative);

        self::assertFileDoesNotExist($this->paths->documentFile(self::VENUE, 'doc-id'));
        self::assertFileDoesNotExist($this->paths->original($relative));
    }
}
