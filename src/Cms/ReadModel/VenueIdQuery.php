<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * The id of a venue by its number, published or not (#25): the first of the two queries of each
 * media read model, which answer null for an unknown venue and a list otherwise.
 */
final class VenueIdQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function byNumber(int $number): ?string
    {
        if ($number < 1 || $number > VenueHeaderQuery::MAX_NUMBER) {
            return null;
        }

        $id = $this->connection->fetchOne('SELECT id FROM venues WHERE number = :number', ['number' => $number]);

        return false === $id ? null : (string) $id;
    }
}
