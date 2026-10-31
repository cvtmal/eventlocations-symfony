<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\DashboardWidget;
use App\Admin\Extension\WidgetSize;
use App\Admin\Security\AdminIdentity;
use App\Crm\ReadModel\TaskListQuery;
use App\Crm\ReadModel\TaskPreview;
use App\Crm\ReadModel\TaskView;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * "Meine Aufgaben" (`Main.dc.html`): the viewer's next open tasks, soonest first, with their toggle and due pill.
 */
final readonly class MyTasksWidget implements DashboardWidget
{
    public const int LIMIT = 5;

    public function __construct(
        private TaskListQuery $tasks,
        private Security $security,
    ) {
    }

    public function title(): TranslatableInterface
    {
        return new TranslatableMessage('task.widget.mine.title', [], 'crm');
    }

    public function template(): string
    {
        return 'admin/crm/widgets/_tasks.html.twig';
    }

    public function context(): array
    {
        $user = $this->security->getUser();

        return [
            'preview' => $user instanceof AdminIdentity ? $this->tasks->mine($user->id(), self::LIMIT) : new TaskPreview([], 0),
            'view' => TaskView::Mine,
            'empty' => new TranslatableMessage('task.widget.mine.empty', [], 'crm'),
            'show_assignee' => false,
        ];
    }

    public function size(): WidgetSize
    {
        return WidgetSize::Medium;
    }

    /**
     * Above #24's widgets (200, 100), leaving 500 for #29's Neue Anfragen (D20).
     */
    public function priority(): int
    {
        return 400;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
