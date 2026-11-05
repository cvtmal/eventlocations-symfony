<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues\Cms;

use App\Media\MediaPaths;
use App\Tests\Integration\Venues\DispatchesVenueCommands;
use App\Tests\Support\Content\ContentFolder;
use App\Tests\Support\Media\MediaFiles;
use App\Tests\Support\Media\TestImages;
use App\Venues\Command\AddVenuePhoto;
use App\Venues\ValueObject\AddedPhoto;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * The #25 media command tests: a rolled-back transaction (each command's own transaction
 * nests as a savepoint, and the after-commit messages still run), uploaded files written at
 * runtime, and the originals under MEDIA_ROOT/cms and the public media removed in tearDown()
 * (#5 rule). Use in a KernelTestCase with Factories.
 */
trait HandlesVenueMedia
{
    use DispatchesVenueCommands;

    private Connection $connection;
    private EntityManagerInterface $em;
    private MediaPaths $paths;
    private string $uploads;
    private int $uploadCount = 0;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $paths = $container->get(MediaPaths::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(MediaPaths::class, $paths);
        $this->em = $em;
        $this->paths = $paths;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->uploads = \dirname($paths->publicDirectory()).'/uploads-'.uniqid();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        while ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        MediaFiles::removeDirectory($this->uploads);
        MediaFiles::removeDirectory($this->paths->original('cms'));
        MediaFiles::removeDirectory($this->paths->publicDirectory());
        parent::tearDown();
    }

    /**
     * An uploaded file as PHP leaves it in its temporary directory; by default a JPEG with
     * other bytes on every call (one colour per call), so two uploads are two originals.
     */
    private function upload(string $bytes = '', string $name = 'foto.jpg'): string
    {
        if ('' === $bytes) {
            $shade = 0x2F6B9D * ++$this->uploadCount;
            $bytes = TestImages::uniformJpeg(480, 320, ($shade >> 16) & 0xFF, ($shade >> 8) & 0xFF, $shade & 0xFF);
        }

        return TestImages::write($this->uploads, uniqid().'-'.$name, $bytes);
    }

    /**
     * An uploaded PDF; another text gives other bytes, so another original.
     */
    private function uploadPdf(string $text = 'Fiktive Preisliste'): string
    {
        return TestImages::write($this->uploads, uniqid().'-dokument.pdf', ContentFolder::pdf($text));
    }

    /**
     * The row token of a room or document, as the read models give it.
     */
    private function rowToken(string $table, string $id): string
    {
        \assert(\in_array($table, ['rooms', 'venue_documents'], true));

        return (string) $this->connection->fetchOne(\sprintf('SELECT EXTRACT(EPOCH FROM updated_at)::bigint FROM %s WHERE id = ?', $table), [$id]);
    }

    /**
     * Moves a row's updated_at one hour back, so a save visibly moves its token.
     */
    private function ageRow(string $table, string $id): void
    {
        \assert(\in_array($table, ['rooms', 'venue_documents'], true));
        $this->connection->executeStatement(\sprintf("UPDATE %s SET updated_at = updated_at - INTERVAL '1 hour' WHERE id = ?", $table), [$id]);
    }

    private function addPhoto(int $venueNumber, string $source, ?string $roomId = null, string $extension = 'jpg'): AddedPhoto
    {
        $added = $this->dispatch(new AddVenuePhoto($venueNumber, $roomId, $source, $extension));
        self::assertInstanceOf(AddedPhoto::class, $added);
        $this->em->clear();

        return $added;
    }

    /**
     * What the handler threw, unwrapped from the bus.
     */
    private function failure(object $command): \Throwable
    {
        try {
            $this->dispatch($command);
        } catch (HandlerFailedException $failure) {
            return $failure->getPrevious() ?? $failure;
        }

        self::fail('The command should have failed.');
    }

    /**
     * The photo's row token, as the island reads it.
     */
    private function photoToken(string $photoId): string
    {
        return (string) $this->connection->fetchOne('SELECT EXTRACT(EPOCH FROM updated_at)::bigint FROM venue_photos WHERE id = ?', [$photoId]);
    }

    /**
     * @return list<string> photo ids of the gallery (room null) or the room, by position
     */
    private function scope(string $venueId, ?string $roomId = null): array
    {
        /** @var list<string> $ids */
        $ids = null === $roomId
            ? $this->connection->fetchFirstColumn('SELECT id FROM venue_photos WHERE venue_id = ? AND room_id IS NULL ORDER BY position', [$venueId])
            : $this->connection->fetchFirstColumn('SELECT id FROM venue_photos WHERE venue_id = ? AND room_id = ? ORDER BY position', [$venueId, $roomId]);

        return $ids;
    }

    /**
     * @return list<int> the positions of the scope, in order (contiguous means 1..n)
     */
    private function positions(string $venueId, ?string $roomId = null): array
    {
        $positions = null === $roomId
            ? $this->connection->fetchFirstColumn('SELECT position FROM venue_photos WHERE venue_id = ? AND room_id IS NULL ORDER BY position', [$venueId])
            : $this->connection->fetchFirstColumn('SELECT position FROM venue_photos WHERE venue_id = ? AND room_id = ? ORDER BY position', [$venueId, $roomId]);

        return array_map(intval(...), $positions);
    }

    private function cmsEdited(int $venueNumber): bool
    {
        return null !== $this->connection->fetchOne('SELECT cms_edited_at FROM venues WHERE number = ?', [$venueNumber]);
    }
}
