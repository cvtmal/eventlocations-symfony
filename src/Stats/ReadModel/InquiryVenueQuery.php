<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * The number of the venue an inquiry went to (#47), for the `inquiry` spool line. Null for an
 * unknown inquiry or one without a venue.
 */
final class InquiryVenueQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function numberOf(string $inquiryId): ?int
    {
        $number = $this->connection->fetchOne(
            'SELECT v.number FROM inquiries i JOIN venues v ON v.id = i.venue_id WHERE i.id = :id',
            ['id' => $inquiryId],
        );

        return false === $number || null === $number ? null : (int) $number;
    }
}
