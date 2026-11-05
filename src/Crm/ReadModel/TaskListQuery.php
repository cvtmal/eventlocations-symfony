<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\RecordKind;
use App\Crm\ValueObject\TaskDueDate;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;

/**
 * The tasks (CRM-5): one tab of the task list as COUNT, then one page of rows; a record's open tasks and a
 * dashboard widget's in one statement each; a task's state for the check before a toggle (P1). "Today" is Zurich's, from the clock.
 */
final class TaskListQuery
{
    public const int PER_PAGE = 50;

    private const string COLUMNS = <<<'SQL'
        SELECT t.id, t.title, t.due_on, t.done_at, u.display_name AS assignee_name, t.company_id, co.name AS company_name,
               t.deal_id, dc.name AS deal_company_name
        SQL;

    private const string FROM = <<<'SQL'
        FROM crm_tasks t
        JOIN admin_users u ON u.id = t.assignee_id
        LEFT JOIN crm_companies co ON co.id = t.company_id
        LEFT JOIN crm_deals d ON d.id = t.deal_id
        LEFT JOIN crm_companies dc ON dc.id = d.company_id
        SQL;

    /** Open first, then by due date. */
    private const string ORDER = ' ORDER BY t.done_at IS NOT NULL, t.due_on ASC, lower(t.title) ASC, t.id ASC';

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    public function page(TaskView $view, string $viewerId, int $page): TaskListPage
    {
        [$condition, $parameters] = $this->condition($view, $viewerId);

        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_tasks t'.$condition, $parameters);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);

        $rows = $total > 0 ? $this->connection->fetchAllAssociative(
            self::COLUMNS.' '.self::FROM.$condition.self::ORDER.' LIMIT :limit OFFSET :offset',
            $parameters + ['limit' => self::PER_PAGE, 'offset' => ($page - 1) * self::PER_PAGE],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        ) : [];

        return new TaskListPage(array_map(self::row(...), $rows), $total, $page, self::PER_PAGE, $lastPage);
    }

    /**
     * The Aufgaben card. A task has one object (D8), so a company's card also collects the tasks on its deals, as P8
     * does for timeline entries, or a renewal task would show nowhere but on the deal (D25).
     *
     * @return list<TaskRow>
     */
    public function openOf(RecordKind $kind, string $id): array
    {
        $record = 't.'.$kind->taskColumn().' = :record'.(RecordKind::Company === $kind ? ' OR d.company_id = :record' : '');

        return array_map(self::row(...), $this->connection->fetchAllAssociative(
            self::COLUMNS.' '.self::FROM.' WHERE t.done_at IS NULL AND ('.$record.')'.self::ORDER,
            ['record' => $id],
        ));
    }

    /**
     * The viewer's next open tasks, for the dashboard (D20).
     */
    public function mine(string $viewerId, int $limit): TaskPreview
    {
        return $this->preview(TaskView::Mine, $viewerId, $limit);
    }

    /**
     * The oldest overdue tasks of anyone, for the dashboard (D20).
     */
    public function overdue(int $limit): TaskPreview
    {
        return $this->preview(TaskView::Overdue, '', $limit);
    }

    /**
     * Whether the task is done; null when it is gone.
     */
    public function isDone(string $id): ?bool
    {
        $row = $this->connection->fetchAssociative('SELECT done_at FROM crm_tasks WHERE id = ?', [$id]);

        return false === $row ? null : null !== $row['done_at'];
    }

    /**
     * The first rows of a tab with its total, in one statement.
     */
    private function preview(TaskView $view, string $viewerId, int $limit): TaskPreview
    {
        [$condition, $parameters] = $this->condition($view, $viewerId);

        $rows = $this->connection->fetchAllAssociative(
            self::COLUMNS.', COUNT(*) OVER () AS total '.self::FROM.$condition.self::ORDER.' LIMIT :limit',
            $parameters + ['limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return new TaskPreview(array_map(self::row(...), $rows), (int) ($rows[0]['total'] ?? 0));
    }

    /**
     * @return array{string, array<string, string>}
     */
    private function condition(TaskView $view, string $viewerId): array
    {
        return match ($view) {
            TaskView::Mine => [' WHERE t.done_at IS NULL AND t.assignee_id = :viewer', ['viewer' => $viewerId]],
            TaskView::Today => [' WHERE t.done_at IS NULL AND t.due_on = :today', ['today' => $this->today()]],
            TaskView::Overdue => [' WHERE t.done_at IS NULL AND t.due_on < :today', ['today' => $this->today()]],
            TaskView::All => ['', []],
        };
    }

    private function today(): string
    {
        return TaskDueDate::today($this->clock->now());
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function row(array $row): TaskRow
    {
        return new TaskRow(
            (string) $row['id'],
            (string) $row['title'],
            (string) $row['due_on'],
            null !== $row['done_at'],
            (string) $row['assignee_name'],
            null === $row['company_id'] ? null : (string) $row['company_id'],
            null === $row['company_name'] ? null : (string) $row['company_name'],
            null === $row['deal_id'] ? null : (string) $row['deal_id'],
            null === $row['deal_company_name'] ? null : (string) $row['deal_company_name'],
        );
    }
}
