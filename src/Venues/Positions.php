<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\Entity\Room;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueDocument;
use App\Venues\Entity\VenuePhoto;
use App\Venues\Entity\VenueVideo;
use App\Venues\Exception\StaleEdit;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Positions of a venue's ordered rows (#25, D11): photos per scope (the gallery, room NULL,
 * and each room), rooms and documents. They run 1..n without gaps (the gallery's first is the
 * cover, D12). The position uniques are not deferrable and the columns are SMALLINT > 0, so a
 * renumber moves the changed rows out of the way first (POSITION_OFFSET), flushes, then gives
 * the final positions and flushes again.
 *
 * The content import keeps its own copy of the offset step (ImportVenueContentHandler, the
 * #9/#32 hotspot), because it moves rows of all three kinds in one flush with its other
 * writes; a later cleanup may route it here.
 *
 * Call it only with the venue locked (VenueEditing::lock()), so no other write takes a
 * position in between.
 */
final class Positions
{
    public const int POSITION_OFFSET = 10000;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Gives the rows of one scope the positions 1..n in the given order. The list must hold the
     * whole scope (rows of other scopes are not looked at). Rows already in place keep their
     * position and updated_at.
     *
     * @param list<VenuePhoto>|list<Room>|list<VenueDocument>|list<VenueVideo> $rowsInOrder
     */
    public function renumber(array $rowsInOrder): void
    {
        $moving = [];
        foreach ($rowsInOrder as $index => $row) {
            if ($row->position() !== $index + 1) {
                $moving[$index] = $row;
            }
        }
        if ([] === $moving) {
            return;
        }

        foreach ($moving as $index => $row) {
            $row->moveTo(self::POSITION_OFFSET + $index + 1);
        }
        $this->em->flush();
        foreach ($moving as $index => $row) {
            $row->moveTo($index + 1);
        }
        $this->em->flush();
    }

    /**
     * The rows of a scope in the order an island or a page sent (D11): null when that is their
     * order already. The ids must be exactly the scope's, else the sender showed an older set.
     *
     * @template T of VenuePhoto|Room|VenueDocument|VenueVideo
     *
     * @param list<T>      $rows the whole scope, by position
     * @param list<string> $ids
     *
     * @return list<T>|null
     *
     * @throws StaleEdit
     */
    public static function inOrder(array $rows, array $ids, int $venueNumber): ?array
    {
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->id()] = $row;
        }
        $current = array_keys($byId);
        $wanted = $ids;
        sort($current);
        sort($wanted);
        if ($current !== $wanted) {
            throw StaleEdit::forVenue($venueNumber);
        }

        if (array_keys($byId) === $ids) {
            return null;
        }
        $ordered = [];
        foreach ($ids as $id) {
            $ordered[] = $byId[$id];
        }

        return $ordered;
    }

    /**
     * The position after the last photo of the gallery (room null) or of the room. Reads the
     * database, so flush pending moves first.
     */
    public function nextPhoto(Venue $venue, ?Room $room): int
    {
        $query = $this->em->createQueryBuilder()
            ->select('COALESCE(MAX(p.position), 0)')
            ->from(VenuePhoto::class, 'p')
            ->where('p.venue = :venue')
            ->setParameter('venue', $venue->id());
        null === $room
            ? $query->andWhere('p.room IS NULL')
            : $query->andWhere('p.room = :room')->setParameter('room', $room->id());

        return (int) $query->getQuery()->getSingleScalarResult() + 1;
    }

    public function nextRoom(Venue $venue): int
    {
        return $this->next(Room::class, $venue);
    }

    public function nextDocument(Venue $venue): int
    {
        return $this->next(VenueDocument::class, $venue);
    }

    public function nextVideo(Venue $venue): int
    {
        return $this->next(VenueVideo::class, $venue);
    }

    /**
     * @param class-string<Room|VenueDocument|VenueVideo> $class
     */
    private function next(string $class, Venue $venue): int
    {
        $max = $this->em->createQueryBuilder()
            ->select('COALESCE(MAX(r.position), 0)')
            ->from($class, 'r')
            ->where('r.venue = :venue')
            ->setParameter('venue', $venue->id())
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $max + 1;
    }
}
