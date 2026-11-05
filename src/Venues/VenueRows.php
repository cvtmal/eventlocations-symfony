<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\Entity\Room;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueDocument;
use App\Venues\Entity\VenuePhoto;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\Repository\VenueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Loads a venue's child rows for the CMS media commands (#25): one photo, room or document
 * by id, and the ordered rows of a scope. A row of another venue, and an id that is
 * not a UUID, is VenueRowNotFound (D21), so a crafted id never reaches Postgres as a GUID.
 *
 * Every lookup is a query, so a row deleted by a concurrent save is gone after
 * VenueEditing::lock(), not a stale object. A query still returns an entity that is already
 * managed unrefreshed, so its values can be older than the committed row: a test calls
 * `$em->clear()` before a token-checked command.
 */
final class VenueRows
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VenueRepository $venues,
    ) {
    }

    /**
     * The venue without a lock, for checks before the CPU-heavy part of a write (AddVenuePhoto
     * stages before it locks, PF3).
     *
     * @throws VenueNotFound
     */
    public function venue(int $venueNumber): Venue
    {
        $id = $this->venues->idByNumber($venueNumber);
        $venue = null === $id ? null : $this->em->find(Venue::class, $id);
        if (null === $venue) {
            throw VenueNotFound::forNumber($venueNumber);
        }

        return $venue;
    }

    /**
     * @throws VenueRowNotFound
     */
    public function photo(Venue $venue, string $photoId): VenuePhoto
    {
        return $this->find(VenuePhoto::class, $venue, $photoId) ?? throw VenueRowNotFound::photo($venue->number(), $photoId);
    }

    /**
     * @throws VenueRowNotFound
     */
    public function room(Venue $venue, string $roomId): Room
    {
        return $this->find(Room::class, $venue, $roomId) ?? throw VenueRowNotFound::room($venue->number(), $roomId);
    }

    /**
     * @throws VenueRowNotFound
     */
    public function document(Venue $venue, string $documentId): VenueDocument
    {
        return $this->find(VenueDocument::class, $venue, $documentId) ?? throw VenueRowNotFound::document($venue->number(), $documentId);
    }

    /**
     * The photos of the gallery (room null) or of one room, by position.
     *
     * @return list<VenuePhoto>
     */
    public function photos(Venue $venue, ?Room $room): array
    {
        $query = $this->em->createQueryBuilder()
            ->select('p')
            ->from(VenuePhoto::class, 'p')
            ->where('p.venue = :venue')
            ->setParameter('venue', $venue->id())
            ->orderBy('p.position');
        null === $room
            ? $query->andWhere('p.room IS NULL')
            : $query->andWhere('p.room = :room')->setParameter('room', $room->id());

        /** @var list<VenuePhoto> $photos */
        $photos = $query->getQuery()->getResult();

        return $photos;
    }

    /**
     * The photo of this scope made from this original, if any (#25 D5: the import's identity
     * of a photo, so a repeated upload finds its first row).
     */
    public function photoWithOriginal(Venue $venue, ?Room $room, string $originalPath): ?VenuePhoto
    {
        foreach ($this->photos($venue, $room) as $photo) {
            if ($photo->originalPath() === $originalPath) {
                return $photo;
            }
        }

        return null;
    }

    /**
     * The venue's document stored at this path, if any (#25: the path is content-addressed, so
     * this is the same PDF uploaded again).
     */
    public function documentWithPath(Venue $venue, string $path): ?VenueDocument
    {
        foreach ($this->documents($venue) as $document) {
            if ($document->path() === $path) {
                return $document;
            }
        }

        return null;
    }

    /**
     * Whether any row of any venue still uses the original (PF1: demo venues share theirs, and
     * one file may sit in the gallery and in a room).
     */
    public function isOriginalUsed(string $originalPath): bool
    {
        return false !== $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM venue_photos WHERE original_path = :path UNION ALL SELECT 1 FROM venue_documents WHERE path = :path LIMIT 1',
            ['path' => $originalPath],
        );
    }

    /**
     * @return list<Room>
     */
    public function rooms(Venue $venue): array
    {
        /** @var list<Room> $rooms */
        $rooms = $this->em->getRepository(Room::class)->findBy(['venue' => $venue], ['position' => 'ASC']);

        return $rooms;
    }

    /**
     * @return list<VenueDocument>
     */
    public function documents(Venue $venue): array
    {
        /** @var list<VenueDocument> $documents */
        $documents = $this->em->getRepository(VenueDocument::class)->findBy(['venue' => $venue], ['position' => 'ASC']);

        return $documents;
    }

    /**
     * @template T of VenuePhoto|Room|VenueDocument
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function find(string $class, Venue $venue, string $id): ?object
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        /** @var T|null $row */
        $row = $this->em->createQueryBuilder()
            ->select('r')
            ->from($class, 'r')
            ->where('r.id = :id AND r.venue = :venue')
            ->setParameter('id', $id)
            ->setParameter('venue', $venue->id())
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }
}
