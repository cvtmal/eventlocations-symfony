<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The count beside "Prüfung" in the sidebar: the open access requests. The dashboard widget reuses it, so a page runs
 * the statement at most once.
 */
final class PortalAccessRequestBadge implements ResetInterface
{
    private const string COUNT = "SELECT COUNT(*) FROM portal_venue_access WHERE status = 'pending'";

    private ?int $count = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * At most one statement per request.
     */
    public function count(): int
    {
        return $this->count ??= (int) $this->connection->fetchOne(self::COUNT);
    }

    public function reset(): void
    {
        $this->count = null;
    }
}
