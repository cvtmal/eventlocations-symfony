<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\NavigationItem;
use App\Admin\Extension\NavigationSection;
use App\Crm\ReadModel\TaskBadge;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * "Aufgaben" in the CRM section, with the viewer's open tasks as its badge.
 */
final readonly class TasksNavigationItem implements NavigationItem
{
    public function __construct(private TaskBadge $badge)
    {
    }

    public function section(): NavigationSection
    {
        return NavigationSection::Crm;
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('nav.tasks', [], 'crm');
    }

    public function icon(): string
    {
        return 'square-check-big';
    }

    public function route(): string
    {
        return 'admin_crm_tasks';
    }

    public function routeParameters(): array
    {
        return [];
    }

    public function badge(): ?int
    {
        $count = $this->badge->count();

        return 0 === $count ? null : $count;
    }

    /**
     * Last in the section, below #28's Deals (300) and #29's Anfragen (200) (D20).
     */
    public function priority(): int
    {
        return 100;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
