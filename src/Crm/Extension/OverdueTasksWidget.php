<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\DashboardWidget;
use App\Admin\Extension\WidgetSize;
use App\Crm\ReadModel\TaskListQuery;
use App\Crm\ReadModel\TaskView;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * "Überfällig" (`### 27.`; the design has it only as a pill): the oldest open tasks past their date, whoever holds them,
 * as the task list's tab of that name, which it links to. So a row names its assignee.
 */
final readonly class OverdueTasksWidget implements DashboardWidget
{
    public const int LIMIT = 5;

    public function __construct(private TaskListQuery $tasks)
    {
    }

    public function title(): TranslatableInterface
    {
        return new TranslatableMessage('task.widget.overdue.title', [], 'crm');
    }

    public function template(): string
    {
        return 'admin/crm/widgets/_tasks.html.twig';
    }

    public function context(): array
    {
        return [
            'preview' => $this->tasks->overdue(self::LIMIT),
            'view' => TaskView::Overdue,
            'empty' => new TranslatableMessage('task.widget.overdue.empty', [], 'crm'),
            'show_assignee' => true,
        ];
    }

    public function size(): WidgetSize
    {
        return WidgetSize::Medium;
    }

    /**
     * Below "Meine Aufgaben" (400), above #24's widgets (D20).
     */
    public function priority(): int
    {
        return 300;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
