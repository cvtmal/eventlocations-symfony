<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\Entity\Venue;
use App\Venues\Exception\VenueRuleViolation;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * The write side of the entitlements (F12): a handler asks here before it adds a photo, a room or a document, or sets
 * the event types, and gets a `VenueRuleViolation` with a message that names the plan and the limit. The read side
 * cuts the same limits when a page is rendered (a venue that fell back to a smaller plan keeps its rows, hidden), so a
 * limit is never a reason to delete anything.
 *
 * The count is the venue's total: photos of the gallery and of its rooms together. Limits come from the
 * `EntitlementResolver` only. The import does not ask here; it writes what staff put in a folder.
 */
final class TierLimits
{
    public function __construct(
        private readonly EntitlementResolver $entitlements,
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws VenueRuleViolation when the venue already holds as many photos as its plan allows
     */
    public function assertPhotoFits(Venue $venue): void
    {
        $entitlements = $this->entitlements->forVenue($venue->id(), $this->clock->now());
        if ($this->count('venue_photos', $venue) >= $entitlements->photosMax) {
            throw VenueRuleViolation::tooManyPhotos($entitlements->photosMax, $entitlements->tierName);
        }
    }

    /**
     * @throws VenueRuleViolation
     */
    public function assertRoomFits(Venue $venue): void
    {
        $entitlements = $this->entitlements->forVenue($venue->id(), $this->clock->now());
        if ($this->count('rooms', $venue) >= $entitlements->roomsMax) {
            throw VenueRuleViolation::tooManyRooms($entitlements->roomsMax, $entitlements->tierName);
        }
    }

    /**
     * @throws VenueRuleViolation
     */
    public function assertDocumentFits(Venue $venue): void
    {
        $entitlements = $this->entitlements->forVenue($venue->id(), $this->clock->now());
        if ($this->count('venue_documents', $venue) >= $entitlements->documentsMax) {
            throw VenueRuleViolation::tooManyDocuments($entitlements->documentsMax, $entitlements->tierName);
        }
    }

    /**
     * @param int $count how many event types the venue will have after the save
     *
     * @throws VenueRuleViolation
     */
    public function assertEventTypesFit(Venue $venue, int $count, string $field): void
    {
        $entitlements = $this->entitlements->forVenue($venue->id(), $this->clock->now());
        if (!$entitlements->allowsEventTypes($count)) {
            \assert(null !== $entitlements->eventTypesMax);

            throw VenueRuleViolation::tooManyEventTypes($entitlements->eventTypesMax, $entitlements->tierName, $field);
        }
    }

    private function count(string $table, Venue $venue): int
    {
        // $table is one of three literals above, never input.
        return (int) $this->connection->fetchOne(\sprintf('SELECT count(*) FROM %s WHERE venue_id = :id', $table), ['id' => $venue->id()]);
    }
}
