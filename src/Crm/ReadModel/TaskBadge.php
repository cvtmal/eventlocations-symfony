<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Admin\Security\AdminIdentity;
use App\Crm\ValueObject\TaskDueDate;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The count beside "Aufgaben" in the sidebar: the viewer's open tasks due today or overdue (D20). The sidebar is on
 * every back-office page and the company list is at its ceiling (P12), so a statement of its own cannot sit on each of
 * them. `OwnerOptionsQuery::all()`, which the lists, the task list and the records run before they render, carries the
 * count as a column and hands it over here; only a page without that statement, like the dashboard, runs `count()`'s.
 * The one statement that is on every page, the user's refresh, is Admin's, and Admin may not know `crm_tasks`.
 */
final class TaskBadge implements ResetInterface
{
    private const string COUNT = 'SELECT COUNT(*) FROM crm_tasks t WHERE t.assignee_id = :viewer AND t.done_at IS NULL AND t.due_on <= :today';

    private ?int $count = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The viewer's count, null with no user. At most one statement per request.
     */
    public function count(): ?int
    {
        $viewer = $this->viewer();
        if (null === $viewer) {
            return null;
        }

        return $this->count ??= (int) $this->connection->fetchOne(self::COUNT, $this->parameters($viewer));
    }

    /**
     * The count as a column for a statement that runs anyway, with that column's parameters; `NULL` with no user.
     *
     * @return array{string, array<string, string>}
     */
    public function column(): array
    {
        $viewer = $this->viewer();

        return null === $viewer ? ['NULL', []] : ['('.self::COUNT.')', $this->parameters($viewer)];
    }

    /**
     * What `column()` gave in the statement that carried it. Nothing writes tasks between that statement and the render:
     * a page that writes redirects.
     */
    public function remember(?int $count): void
    {
        $this->count = $count;
    }

    public function reset(): void
    {
        $this->count = null;
    }

    private function viewer(): ?string
    {
        $user = $this->security->getUser();

        return $user instanceof AdminIdentity ? $user->id() : null;
    }

    /**
     * @return array<string, string>
     */
    private function parameters(string $viewer): array
    {
        return ['viewer' => $viewer, 'today' => TaskDueDate::today($this->clock->now())];
    }
}
