<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

use App\Venues\EntitlementResolver;
use App\Venues\ValueObject\KnownTiers;
use Doctrine\DBAL\Connection;

/**
 * The plan card of a manager's location (F12): the limits come from `EntitlementResolver`, the one answer to "what
 * may this venue do", and the counts from the rows themselves. The Pro price is read from the Pro tier's row, so a
 * price staff change on "Tarife" is the price the card names.
 */
final class LocationPlanQuery
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntitlementResolver $entitlements,
    ) {
    }

    /**
     * @param string $venueId `venues.id`
     */
    public function forVenue(string $venueId, \DateTimeImmutable $now): LocationPlan
    {
        $entitlements = $this->entitlements->forVenue($venueId, $now);

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT (SELECT count(*) FROM venue_photos WHERE venue_id = :id) AS photos,
                       (SELECT count(*) FROM rooms WHERE venue_id = :id) AS rooms,
                       (SELECT count(*) FROM venue_documents WHERE venue_id = :id) AS documents,
                       (SELECT price_rappen FROM tiers WHERE code = :pro) AS pro_price_rappen
                SQL,
            ['id' => $venueId, 'pro' => KnownTiers::PRO],
        );
        \assert(\is_array($row) && \is_int($row['photos']) && \is_int($row['rooms']) && \is_int($row['documents']));
        \assert(null === $row['pro_price_rappen'] || \is_int($row['pro_price_rappen']));

        return new LocationPlan(
            $entitlements->tierName,
            $entitlements->isPromoted(),
            null !== $row['pro_price_rappen'] && KnownTiers::PRO !== $entitlements->tierCode,
            $row['photos'],
            $entitlements->photosMax,
            $row['rooms'],
            $entitlements->roomsMax,
            $row['documents'],
            $entitlements->documentsMax,
            $entitlements->contactVisible,
            $entitlements->eventTypesMax,
            $row['pro_price_rappen'],
        );
    }
}
