<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

use App\Portal\ValueObject\PortalVenueAccessStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Where a manager stands on a set of locations (#43): the search's pills, and the read before an ask, because a
 * no-op command would still write an audit row. A location without a row is absent from the answer.
 */
final class ManagerAccessQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param list<int> $numbers
     *
     * @return array<int, PortalVenueAccessStatus>
     */
    public function statuses(string $portalUserId, array $numbers): array
    {
        if ([] === $numbers) {
            return [];
        }

        $rows = $this->connection->fetchAllKeyValue(
            <<<'SQL'
                SELECT v.number, a.status
                FROM portal_venue_access a
                JOIN venues v ON v.id = a.venue_id
                WHERE a.portal_user_id = :user AND v.number IN (:numbers)
                SQL,
            ['user' => $portalUserId, 'numbers' => $numbers],
            ['numbers' => ArrayParameterType::INTEGER],
        );

        $statuses = [];
        foreach ($rows as $number => $status) {
            $statuses[(int) $number] = PortalVenueAccessStatus::from((string) $status);
        }

        return $statuses;
    }
}
