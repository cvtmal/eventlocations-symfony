<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\Command\PurgeListingsCache;
use App\Venues\Command\PurgeVenueCache;
use App\Venues\Entity\Venue;
use App\Venues\Exception\StaleEdit;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Repository\VenueRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * The one way a back-office command handler (#24, #25, #28) opens and closes a venue it writes.
 *
 * - lock() takes the venue row with SELECT … FOR UPDATE until the command's transaction
 *   ends, so two saves, or a save and an import (which locks the same row before reading its
 *   guard), never interleave.
 * - open() also compares the form's `loadedAt` token (`venues.updated_at` as Unix seconds,
 *   read from the database when the form was rendered) with the locked row, and throws
 *   StaleEdit when someone saved in between (S12). The token must come from the database:
 *   updated_at is TIMESTAMP(0), so an entity touched in the same request holds a value the
 *   database rounds.
 * - close() records the CMS edit (markCmsEdited(), which also moves updated_at, so a change
 *   to a child row alone still stales other open forms) and purges the venue's pages, and the
 *   listings when asked, after the commit.
 *
 * Only staff and manager commands call it, Cms's, Portal's and `ChangeVenueTier`, which the Crm
 * dispatches for a won deal (#28); the import never does. close() is what protects staff work
 * and hand-made rows from an import (S8: the import skips a venue with cms_edited_at set). A
 * handler that changed nothing returns before close().
 *
 * The Google place commands (#53) close with `markCmsEdited: false`: the place ID is outside the
 * content format, so no import can overwrite it and it needs no S8 protection, while a stamp would
 * freeze the venue against every later folder import. The entity moves updated_at itself, so
 * other open forms still go stale.
 */
final class VenueEditing
{
    public function __construct(
        private readonly VenueRepository $venues,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Loads and locks the venue for the current transaction. For writes that carry no form
     * state (publish, delete, a photo added or moved).
     *
     * @throws VenueNotFound
     */
    public function lock(int $venueNumber): Venue
    {
        $id = $this->venues->idByNumber($venueNumber);
        $venue = null === $id ? null : $this->em->find(Venue::class, $id, LockMode::PESSIMISTIC_WRITE);
        if (null === $venue) {
            throw VenueNotFound::forNumber($venueNumber);
        }

        return $venue;
    }

    /**
     * lock(), then the stale check against the form's token.
     *
     * @param string $loadedAt `venues.updated_at` in Unix seconds when the form was rendered
     *
     * @throws VenueNotFound
     * @throws StaleEdit
     */
    public function open(int $venueNumber, string $loadedAt): Venue
    {
        $venue = $this->lock($venueNumber);
        if ($venue->updatedAt()->format('U') !== $loadedAt) {
            throw StaleEdit::forVenue($venueNumber);
        }

        return $venue;
    }

    /**
     * Marks the CMS edit, unless told not to, and purges the HTTP cache once the transaction has
     * committed.
     */
    public function close(Venue $venue, bool $purgeListings = true, bool $markCmsEdited = true): void
    {
        if ($markCmsEdited) {
            $venue->markCmsEdited($this->clock->now());
        }
        $this->commandBus->dispatch(new PurgeVenueCache($venue->number()), [new DispatchAfterCurrentBusStamp()]);
        if ($purgeListings) {
            $this->commandBus->dispatch(new PurgeListingsCache(), [new DispatchAfterCurrentBusStamp()]);
        }
    }
}
