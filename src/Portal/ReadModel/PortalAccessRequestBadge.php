<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The counts beside "Prüfung" in the sidebar: the open access requests and the locations that wait for staff. The
 * dashboard widget reuses the first, so a page runs the statement at most once.
 *
 * @phpstan-type Counts array{requests: int, submissions: int}
 */
final class PortalAccessRequestBadge implements ResetInterface
{
    private const string COUNTS = <<<'SQL'
        SELECT (SELECT COUNT(*) FROM portal_venue_access WHERE status = 'pending') AS requests,
               (SELECT COUNT(*) FROM portal_submissions s JOIN venues v ON v.id = s.venue_id
                WHERE s.status = 'submitted' AND v.status = 'unpublished') AS submissions
        SQL;

    /** @var Counts|null */
    private ?array $counts = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The access requests alone: the widget's total stays theirs.
     */
    public function count(): int
    {
        return $this->counts()['requests'];
    }

    public function submissions(): int
    {
        return $this->counts()['submissions'];
    }

    public function total(): int
    {
        return $this->count() + $this->submissions();
    }

    public function reset(): void
    {
        $this->counts = null;
    }

    /**
     * At most one statement per request.
     *
     * @return Counts
     */
    private function counts(): array
    {
        if (null === $this->counts) {
            $row = $this->connection->fetchAssociative(self::COUNTS);
            \assert(false !== $row);
            $this->counts = ['requests' => (int) $row['requests'], 'submissions' => (int) $row['submissions']];
        }

        return $this->counts;
    }
}
