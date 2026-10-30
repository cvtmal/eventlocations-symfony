<?php

declare(strict_types=1);

namespace App\Admin\Extension;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class DashboardWidgetRegistry
{
    /**
     * @param iterable<DashboardWidget> $widgets
     */
    public function __construct(
        #[AutowireIterator('admin.dashboard_widget')]
        private readonly iterable $widgets,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * @return list<DashboardWidget>
     */
    public function visible(): array
    {
        return PriorityOrder::visible($this->widgets, $this->authorization);
    }
}
