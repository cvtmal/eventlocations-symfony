<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * A venue by its content key or its number, in any status (F14): the owner's consoles name venues by the key of
 * their content folder (the venue sheet) or by number (a withdrawal), and need the other.
 */
final readonly class VenueKeyQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function numberOf(string $contentKey): ?int
    {
        $number = $this->connection->fetchOne('SELECT number FROM venues WHERE content_key = ?', [$contentKey]);

        return false === $number ? null : (int) $number;
    }

    public function keyOf(int $number): ?string
    {
        return $this->find($number)?->contentKey;
    }

    public function find(int $number): ?VenueKey
    {
        $row = $this->connection->fetchAssociative('SELECT id, number, name, content_key, status FROM venues WHERE number = ?', [$number]);
        if (false === $row) {
            return null;
        }

        return new VenueKey(
            (string) $row['id'],
            (int) $row['number'],
            (string) $row['name'],
            null === $row['content_key'] ? null : (string) $row['content_key'],
            'published' === $row['status'],
        );
    }
}
