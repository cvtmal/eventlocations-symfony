<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\VenueFactory;
use App\Media\MediaPaths;
use App\Media\MediaPublisher;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Content\ContentFolder;
use App\Tests\Support\Media\MediaFiles;
use App\Tests\Support\Media\TestImages;
use App\Venues\DocumentPipeline;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueDocument;
use App\Venues\Entity\VenuePhoto;
use App\Venues\PhotoPipeline;
use App\Venues\PublishedMedia;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The photo and document pipelines and PublishedMedia, shared by the content import and #25,
 * against app_test in a rolled-back transaction; originals, staging and public files are
 * removed in tearDown() (#5 rule).
 *
 * The failure clean-up, PublishedMedia::revert(), is proven in two places: directly here, and
 * through the import handler on an existing venue by
 * ImportContentFailuresTest::test_a_publish_failure_removes_only_what_this_write_published
 * (a test-only postFlush listener deletes the stored PDF, so no production seam is needed).
 */
final class VenueMediaPipelineTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private Connection $connection;
    private EntityManagerInterface $em;
    private MediaPaths $paths;
    private MediaPublisher $publisher;
    private PhotoPipeline $photos;
    private DocumentPipeline $documents;
    private string $folder;
    private string $sources;
    private string $staging;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $paths = $container->get(MediaPaths::class);
        $publisher = $container->get(MediaPublisher::class);
        $photos = $container->get(PhotoPipeline::class);
        $documents = $container->get(DocumentPipeline::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(MediaPaths::class, $paths);
        self::assertInstanceOf(MediaPublisher::class, $publisher);
        self::assertInstanceOf(PhotoPipeline::class, $photos);
        self::assertInstanceOf(DocumentPipeline::class, $documents);
        $this->em = $em;
        $this->paths = $paths;
        $this->publisher = $publisher;
        $this->photos = $photos;
        $this->documents = $documents;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->folder = 'test-pipeline-'.uniqid();
        $this->sources = \dirname($paths->publicDirectory()).'/sources-'.uniqid();
        $this->staging = $photos->openStaging();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        $this->photos->discardStaging($this->staging);
        MediaFiles::removeDirectory($this->sources);
        MediaFiles::removeDirectory($this->paths->original($this->folder));
        MediaFiles::removeDirectory($this->paths->publicDirectory());
        parent::tearDown();
    }

    public function test_stage_stores_the_original_once_and_renders_staged_variants(): void
    {
        $source = TestImages::write($this->sources, 'a.jpg', TestImages::jpeg(800, 400));

        $staged = $this->photos->stage($source, $this->folder.'/a.jpg', $this->staging.'/0');

        self::assertSame($this->folder.'/a.jpg', $staged->originalPath);
        self::assertFileExists($this->paths->original($this->folder.'/a.jpg'));
        self::assertSame(\count($staged->image->variants->widths) * \count($staged->image->variants->formats), \count(glob($this->staging.'/0/v-*') ?: []));

        touch($this->paths->original($this->folder.'/a.jpg'), 1_000_000_000);
        clearstatcache();
        $this->photos->stage($source, $this->folder.'/a.jpg', $this->staging.'/1');
        clearstatcache();
        self::assertSame(1_000_000_000, filemtime($this->paths->original($this->folder.'/a.jpg')), 'an existing original is kept');
    }

    public function test_a_photo_and_a_document_are_published_under_their_ids(): void
    {
        $venue = VenueFactory::createOne();
        $published = new PublishedMedia();

        $photo = $this->publishedPhoto($venue, 'a.jpg', 1, $published);
        $document = $this->publishedDocument($venue, 'preise.pdf', 1, $published);

        $number = $venue->number();
        self::assertSame($this->variantCount($photo), \count($this->photoFiles($number, $photo->id())));
        self::assertFileExists($this->paths->documentFile($number, $document->id()));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM venue_photos WHERE venue_id = ?', [$venue->id()]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM venue_documents WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_revert_removes_only_what_this_write_published(): void
    {
        $venue = VenueFactory::createOne();
        $old = $this->publishedPhoto($venue, 'alt.jpg', 1, new PublishedMedia());
        $number = $venue->number();
        $oldFiles = $this->photoFiles($number, $old->id());
        self::assertNotEmpty($oldFiles);

        $published = new PublishedMedia();
        $new = $this->publishedPhoto($venue, 'neu.jpg', 2, $published);
        $document = $this->publishedDocument($venue, 'preise.pdf', 1, $published);
        self::assertNotEmpty($this->photoFiles($number, $new->id()));
        self::assertFileExists($this->paths->documentFile($number, $document->id()));

        $published->revert($this->publisher);

        self::assertSame([], $this->photoFiles($number, $new->id()), "the new photo's variants are gone");
        self::assertFileDoesNotExist($this->paths->documentFile($number, $document->id()), 'the new PDF is gone');
        self::assertSame($oldFiles, $this->photoFiles($number, $old->id()), "the old photo's variants remain");
    }

    public function test_revert_of_a_new_venue_removes_its_directory(): void
    {
        $venue = VenueFactory::createOne();
        $published = new PublishedMedia();
        $published->newVenue($venue->number());
        $this->publishedPhoto($venue, 'a.jpg', 1, $published);
        self::assertDirectoryExists($this->paths->photoDirectory($venue->number()));

        $published->revert($this->publisher);

        self::assertDirectoryDoesNotExist($this->paths->photoDirectory($venue->number()));
    }

    public function test_discard_staging_removes_the_directory(): void
    {
        $source = TestImages::write($this->sources, 'a.jpg', TestImages::jpeg(800, 400));
        $this->photos->stage($source, $this->folder.'/a.jpg', $this->staging.'/0');
        self::assertDirectoryExists($this->staging.'/0');

        $this->photos->discardStaging($this->staging);

        self::assertDirectoryDoesNotExist($this->staging);
    }

    /**
     * @param int<1, 12> $position
     */
    private function publishedPhoto(Venue $venue, string $file, int $position, PublishedMedia $published): VenuePhoto
    {
        $source = TestImages::write($this->sources, $file, TestImages::uniformJpeg(400, 260, 20 * $position, 90, 140));
        $staged = $this->photos->stage($source, $this->folder.'/'.$file, $this->staging.'/'.$file);
        $photo = $this->photos->add($venue, null, $position, $staged);
        $this->em->flush();
        $this->photos->publish($photo, $staged, $published);

        return $photo;
    }

    private function publishedDocument(Venue $venue, string $file, int $position, PublishedMedia $published): VenueDocument
    {
        $source = TestImages::write($this->sources, $file, ContentFolder::pdf());
        $this->documents->store($source, $this->folder.'/'.$file);
        $document = $this->documents->add($venue, $position, $this->folder.'/'.$file, (int) filesize($source));
        $this->em->flush();
        $this->documents->publish($document, $published);

        return $document;
    }

    private function variantCount(VenuePhoto $photo): int
    {
        return \count($photo->variants()->widths) * \count($photo->variants()->formats);
    }

    /**
     * @return list<string> the public variant files of one photo id, sorted
     */
    private function photoFiles(int $number, string $photoId): array
    {
        clearstatcache();
        $files = glob($this->paths->photoDirectory($number).'/'.$photoId.'-*') ?: [];
        sort($files);

        return $files;
    }
}
