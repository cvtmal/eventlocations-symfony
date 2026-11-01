<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The count beside "Anfragen" in the sidebar: the inquiries nobody has looked at. Like `TaskBadge`, because the company
 * list is at its ceiling: `OwnerOptionsQuery::all()` carries the count as a column and hands it over here, and the inbox
 * has it from its tabs, so only a page with neither runs `count()`'s statement.
 */
final class InquiryBadge implements ResetInterface
{
    private const string COUNT = "SELECT COUNT(*) FROM crm_inquiry_links WHERE status = 'new'";

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

    /**
     * The count as a column for a statement that runs anyway.
     */
    public function column(): string
    {
        return '('.self::COUNT.')';
    }

    /**
     * What `column()` gave in the statement that carried it. Nothing writes a status between that statement and the
     * render: a page that writes redirects.
     */
    public function remember(int $count): void
    {
        $this->count = $count;
    }

    public function reset(): void
    {
        $this->count = null;
    }
}
