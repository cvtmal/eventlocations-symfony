<?php

declare(strict_types=1);

namespace App\Admin\Twig;

use App\Admin\Extension\DashboardWidget;
use App\Admin\Extension\DashboardWidgetRegistry;
use App\Admin\Extension\NavigationRegistry;
use App\Admin\Extension\RecordPanelRegistry;
use App\Admin\Extension\RecordTabRegistry;
use App\Admin\ValueObject\NavigationSectionView;
use App\Admin\ValueObject\RecordPanelView;
use App\Admin\ValueObject\RecordTabView;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;

/**
 * The back office's extension points for templates. All of them apply the viewer's role.
 */
final class ExtensionPointsExtension
{
    public function __construct(
        private readonly NavigationRegistry $navigation,
        private readonly DashboardWidgetRegistry $widgets,
        private readonly RecordTabRegistry $tabs,
        private readonly RecordPanelRegistry $panels,
        private readonly RequestStack $requests,
    ) {
    }

    /**
     * @return list<NavigationSectionView>
     */
    #[AsTwigFunction('admin_navigation')]
    public function navigation(): array
    {
        $route = $this->requests->getMainRequest()?->attributes->get('_route');

        return $this->navigation->sections(\is_string($route) ? $route : null);
    }

    /**
     * @return list<DashboardWidget>
     */
    #[AsTwigFunction('admin_dashboard_widgets')]
    public function dashboardWidgets(): array
    {
        return $this->widgets->visible();
    }

    /**
     * @return list<RecordTabView>
     */
    #[AsTwigFunction('admin_record_tabs')]
    public function recordTabs(string $type, string $id): array
    {
        return $this->tabs->for($type, $id);
    }

    /**
     * @return list<RecordPanelView>
     */
    #[AsTwigFunction('admin_record_panels')]
    public function recordPanels(string $type, string $id): array
    {
        return $this->panels->for($type, $id);
    }
}
