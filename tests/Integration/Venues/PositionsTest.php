<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\RoomFactory;
use App\Factory\VenueDocumentFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Entity\Room;
use App\Venues\Entity\VenueDocument;
use App\Venues\Entity\VenuePhoto;
use App\Venues\Positions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Positions (#25, D11): renumbering through the offset without breaking the
 * non-deferrable position uniques, and the next position of each scope.
 */
final class PositionsTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_a_reversed_list_of_photos_ends_one_to_three(): void
    {
        $venue = VenueFactory::createOne();
        $photos = [];
        foreach ([1, 2, 3] as $position) {
            $photos[] = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => $position]);
        }

        $this->positions()->renumber(array_reverse($photos));

        $this->em->clear();
        self::assertSame(
            [$photos[2]->id() => 1, $photos[1]->id() => 2, $photos[0]->id() => 3],
            $this->positionsOf('venue_photos', $venue->id()),
        );
    }

    public function test_a_gap_closes_and_rows_in_place_keep_their_updated_at(): void
    {
        $venue = VenueFactory::createOne();
        $first = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $third = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 3]);
        $this->connection->executeStatement("UPDATE venue_photos SET updated_at = '2026-01-01 00:00:00+00' WHERE venue_id = ?", [$venue->id()]);
        $this->em->clear();
        $firstRow = $this->em->find(VenuePhoto::class, $first->id());
        $thirdRow = $this->em->find(VenuePhoto::class, $third->id());
        self::assertInstanceOf(VenuePhoto::class, $firstRow);
        self::assertInstanceOf(VenuePhoto::class, $thirdRow);

        $this->positions()->renumber([$firstRow, $thirdRow]);

        self::assertSame([$first->id() => 1, $third->id() => 2], $this->positionsOf('venue_photos', $venue->id()));
        self::assertSame('2026-01-01 00:00:00+00', $this->connection->fetchOne('SELECT updated_at FROM venue_photos WHERE id = ?', [$first->id()]));
        self::assertNotSame('2026-01-01 00:00:00+00', $this->connection->fetchOne('SELECT updated_at FROM venue_photos WHERE id = ?', [$third->id()]));
    }

    public function test_rooms_and_documents_are_renumbered(): void
    {
        $venue = VenueFactory::createOne();
        $rooms = [RoomFactory::createOne(['venue' => $venue, 'position' => 1]), RoomFactory::createOne(['venue' => $venue, 'position' => 2])];
        $documents = [VenueDocumentFactory::createOne(['venue' => $venue, 'position' => 1]), VenueDocumentFactory::createOne(['venue' => $venue, 'position' => 2])];

        $this->positions()->renumber([$rooms[1], $rooms[0]]);
        $this->positions()->renumber([$documents[1], $documents[0]]);

        self::assertSame([$rooms[1]->id() => 1, $rooms[0]->id() => 2], $this->positionsOf('rooms', $venue->id()));
        self::assertSame([$documents[1]->id() => 1, $documents[0]->id() => 2], $this->positionsOf('venue_documents', $venue->id()));
    }

    public function test_next_is_one_on_an_empty_scope_and_gallery_and_room_count_apart(): void
    {
        $venue = VenueFactory::createOne();
        $room = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        self::assertInstanceOf(Room::class, $room);
        $positions = $this->positions();

        self::assertSame(1, $positions->nextPhoto($venue, null));
        self::assertSame(1, $positions->nextPhoto($venue, $room));
        self::assertSame(1, $positions->nextDocument($venue));
        self::assertSame(2, $positions->nextRoom($venue));

        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room, 'position' => 1]);
        $document = VenueDocumentFactory::createOne(['venue' => $venue, 'position' => 4]);
        self::assertInstanceOf(VenueDocument::class, $document);

        self::assertSame(3, $positions->nextPhoto($venue, null));
        self::assertSame(2, $positions->nextPhoto($venue, $room));
        self::assertSame(5, $positions->nextDocument($venue));
        self::assertSame(1, $positions->nextPhoto(VenueFactory::createOne(), null));
    }

    private function positions(): Positions
    {
        return new Positions($this->em);
    }

    /**
     * @return array<string, int> id → position, in position order
     */
    private function positionsOf(string $table, string $venueId): array
    {
        /** @var array<string, int> $rows */
        $rows = array_map(intval(...), $this->connection->fetchAllKeyValue("SELECT id, position FROM {$table} WHERE venue_id = ? ORDER BY position", [$venueId]));

        return $rows;
    }
}
