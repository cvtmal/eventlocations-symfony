<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues\Cms;

use App\Admin\Entity\AuditLogEntry;
use App\Factory\VenueFactory;
use App\Media\MediaPaths;
use App\Tests\Integration\Venues\DispatchesVenueCommands;
use App\Tests\Support\Content\ContentFolder;
use App\Tests\Support\Media\MediaFiles;
use App\Tests\Support\Media\TestImages;
use App\Tests\Support\Messenger\AfterCommitSpy;
use App\Venues\Command\AddVenueDocument;
use App\Venues\Command\AddVenuePhoto;
use App\Venues\Command\PurgeListingsCache;
use App\Venues\Command\PurgeVenueCache;
use App\Venues\Command\RemoveVenueDocument;
use App\Venues\Command\RemoveVenueFiles;
use App\Venues\Command\RemoveVenuePhoto;
use App\Venues\Command\ReorderVenuePhotos;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\AddedPhoto;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Purges and file removal wait for the commit (#25, F-M6), on the real bus and against real commits: an
 * outer test transaction would hide what these tests look for, so this class has none and removes its own
 * rows and files. AfterCommitSpy gives the nesting level each message ran at; the failure comes from the
 * final flush of the command's transaction, when the audit row goes in, so it falls after the handler's
 * close() and after its RemoveVenueFiles.
 */
final class AfterCommitTest extends KernelTestCase
{
    use DispatchesVenueCommands;
    use Factories;

    private const string FAILURE = 'The commit failed.';

    private Connection $connection;
    private EntityManagerInterface $em;
    private MediaPaths $paths;
    private AfterCommitSpy $spy;
    private string $uploads;
    private ?object $failingFlush = null;

    /** @var list<Venue> */
    private array $venues = [];

    protected function setUp(): void
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $paths = $container->get(MediaPaths::class);
        $spy = $container->get(AfterCommitSpy::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(MediaPaths::class, $paths);
        self::assertInstanceOf(AfterCommitSpy::class, $spy);
        $this->em = $em;
        $this->paths = $paths;
        $this->spy = $spy;
        $this->connection = $em->getConnection();
        $this->uploads = \dirname($paths->publicDirectory()).'/uploads-'.uniqid();
        self::assertSame(0, $this->connection->getTransactionNestingLevel(), 'These tests need real commits.');
    }

    protected function tearDown(): void
    {
        $this->stopFailing();
        $this->em->clear();
        while ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        foreach ($this->venues as $venue) {
            $this->connection->executeStatement("DELETE FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $venue->number()]);
            $this->connection->executeStatement('DELETE FROM venues WHERE id = ?', [$venue->id()]);
        }
        MediaFiles::removeDirectory($this->uploads);
        MediaFiles::removeDirectory($this->paths->original('cms'));
        MediaFiles::removeDirectory($this->paths->publicDirectory());
        parent::tearDown();
    }

    public function test_a_removed_photo_leaves_after_the_commit(): void
    {
        $venue = $this->venue();
        $photo = $this->addPhoto($venue);
        $original = $this->original($this->photoPath($photo));
        $variants = $this->variants($venue, $photo);
        self::assertNotSame([], $variants);
        self::assertFileExists($original);
        $this->spy->calls = [];

        $this->dispatch(new RemoveVenuePhoto($venue->number(), $photo));

        self::assertSame([[RemoveVenueFiles::class, 1], [PurgeVenueCache::class, 1], [PurgeListingsCache::class, 1]], $this->spy->calls);
        self::assertSame([], $this->variants($venue, $photo));
        self::assertFileDoesNotExist($original);
    }

    public function test_a_failed_photo_removal_keeps_the_row_and_the_files_and_purges_nothing(): void
    {
        $venue = $this->venue();
        $photo = $this->addPhoto($venue);
        $original = $this->original($this->photoPath($photo));
        $variants = $this->variants($venue, $photo);
        self::assertNotSame([], $variants);
        $this->spy->calls = [];
        $this->purger()->venues = [];
        $this->purger()->listings = 0;

        $failure = $this->failingCommit(new RemoveVenuePhoto($venue->number(), $photo));

        self::assertSame(self::FAILURE, $failure?->getMessage());
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_photos WHERE id = ?', [$photo]));
        self::assertSame($variants, $this->variants($venue, $photo));
        self::assertFileExists($original);
        self::assertSame([], $this->spy->calls);
        self::assertSame([[], 0], [$this->purger()->venues, $this->purger()->listings]);
    }

    public function test_a_removed_document_leaves_after_the_commit(): void
    {
        $venue = $this->venue();
        $document = $this->addDocument($venue);
        $original = $this->original($this->documentPath($document));
        self::assertFileExists($this->paths->documentFile($venue->number(), $document));
        self::assertFileExists($original);
        $this->spy->calls = [];

        $this->dispatch(new RemoveVenueDocument($venue->number(), $document));

        self::assertSame([[RemoveVenueFiles::class, 1], [PurgeVenueCache::class, 1]], $this->spy->calls);
        self::assertFileDoesNotExist($this->paths->documentFile($venue->number(), $document));
        self::assertFileDoesNotExist($original);
    }

    public function test_a_failed_document_removal_keeps_the_row_and_the_files_and_purges_nothing(): void
    {
        $venue = $this->venue();
        $document = $this->addDocument($venue);
        $original = $this->original($this->documentPath($document));
        $this->spy->calls = [];
        $this->purger()->venues = [];

        $failure = $this->failingCommit(new RemoveVenueDocument($venue->number(), $document));

        self::assertSame(self::FAILURE, $failure?->getMessage());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_documents WHERE id = ?', [$document]));
        self::assertFileExists($this->paths->documentFile($venue->number(), $document));
        self::assertFileExists($original);
        self::assertSame([], $this->spy->calls);
        self::assertSame([], $this->purger()->venues);
    }

    public function test_a_reorder_purges_after_the_commit_and_a_failed_one_not_at_all(): void
    {
        $venue = $this->venue();
        $first = $this->addPhoto($venue);
        $second = $this->addPhoto($venue);
        $this->spy->calls = [];
        $this->purger()->venues = [];
        $this->purger()->listings = 0;

        $failure = $this->failingCommit(new ReorderVenuePhotos($venue->number(), null, [$second, $first]));

        self::assertSame(self::FAILURE, $failure?->getMessage());
        self::assertSame([$first, $second], $this->gallery($venue));
        self::assertSame([], $this->spy->calls);
        self::assertSame([[], 0], [$this->purger()->venues, $this->purger()->listings]);

        $this->dispatch(new ReorderVenuePhotos($venue->number(), null, [$second, $first]));

        self::assertSame([$second, $first], $this->gallery($venue));
        self::assertSame([[PurgeVenueCache::class, 1], [PurgeListingsCache::class, 1]], $this->spy->calls);
    }

    public function test_a_failed_upload_stores_no_row_and_purges_nothing(): void
    {
        $venue = $this->venue();
        $this->spy->calls = [];

        $failure = $this->failingCommit(new AddVenuePhoto($venue->number(), null, $this->upload(), 'jpg'));

        self::assertSame(self::FAILURE, $failure?->getMessage());
        self::assertSame([], $this->gallery($venue));
        self::assertSame([], $this->spy->calls);
        self::assertSame([], $this->purger()->venues);
    }

    private function venue(): Venue
    {
        $venue = VenueFactory::new()->withoutLocation()->create();
        $this->venues[] = $venue;
        $this->em->clear();

        return $venue;
    }

    private function addPhoto(Venue $venue): string
    {
        $added = $this->dispatch(new AddVenuePhoto($venue->number(), null, $this->upload(), 'jpg'));
        self::assertInstanceOf(AddedPhoto::class, $added);
        $this->em->clear();

        return $added->photoId;
    }

    private function addDocument(Venue $venue): string
    {
        $id = $this->dispatch(new AddVenueDocument($venue->number(), TestImages::write($this->uploads, uniqid().'.pdf', ContentFolder::pdf('Fiktiv')), ['de' => 'Preisliste']));
        self::assertIsString($id);
        $this->em->clear();

        return $id;
    }

    /**
     * A JPEG with other bytes on every call.
     */
    private function upload(): string
    {
        return TestImages::write($this->uploads, uniqid().'.jpg', TestImages::uniformJpeg(480, 320, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    }

    /**
     * Dispatches the command with its commit set to fail and answers what came out of the bus, null when
     * nothing did. The assertions stay outside the catch: this method must not be able to swallow one.
     */
    private function failingCommit(object $command): ?\Throwable
    {
        $this->failingFlush = new class(self::FAILURE) {
            public function __construct(
                private readonly string $failure,
            ) {
            }

            public function onFlush(OnFlushEventArgs $event): void
            {
                foreach ($event->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if ($entity instanceof AuditLogEntry) {
                        throw new \RuntimeException($this->failure);
                    }
                }
            }
        };
        $this->em->getEventManager()->addEventListener(Events::onFlush, $this->failingFlush);

        $failure = null;
        try {
            $this->dispatch($command);
        } catch (\Throwable $thrown) {
            $failure = $thrown;
        }
        $this->stopFailing();
        $this->em->clear();

        return $failure;
    }

    private function stopFailing(): void
    {
        if (null !== $this->failingFlush) {
            $this->em->getEventManager()->removeEventListener(Events::onFlush, $this->failingFlush);
            $this->failingFlush = null;
        }
    }

    private function original(string $path): string
    {
        return $this->paths->original($path);
    }

    private function photoPath(string $id): string
    {
        return (string) $this->connection->fetchOne('SELECT original_path FROM venue_photos WHERE id = ?', [$id]);
    }

    private function documentPath(string $id): string
    {
        return (string) $this->connection->fetchOne('SELECT path FROM venue_documents WHERE id = ?', [$id]);
    }

    /**
     * @return list<string>
     */
    private function variants(Venue $venue, string $photo): array
    {
        return glob($this->paths->photoDirectory($venue->number()).'/'.$photo.'-*') ?: [];
    }

    /**
     * @return list<string>
     */
    private function gallery(Venue $venue): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn('SELECT id FROM venue_photos WHERE venue_id = ? AND room_id IS NULL ORDER BY position', [$venue->id()]);

        return $ids;
    }
}
