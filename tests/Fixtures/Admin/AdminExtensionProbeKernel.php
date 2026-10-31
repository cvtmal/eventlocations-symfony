<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Admin;

use App\Tests\Fixtures\Admin\AuditProbe\AuditProbeHandler;
use App\Tests\Fixtures\Admin\AuditProbe\PlainProbeHandler;
use App\Tests\Fixtures\Admin\FakeModule\FakeAdminOnlyJsonController;
use App\Tests\Fixtures\Admin\FakeModule\FakeAdminOnlyNavigationItem;
use App\Tests\Fixtures\Admin\FakeModule\FakeDashboardWidget;
use App\Tests\Fixtures\Admin\FakeModule\FakeJsonController;
use App\Tests\Fixtures\Admin\FakeModule\FakeNavigationItem;
use App\Tests\Fixtures\Admin\FakeModule\FakeRecordController;
use App\Tests\Fixtures\Admin\FakeModule\FakeRecordPanel;
use App\Tests\Fixtures\Admin\FakeModule\FakeRecordTab;
use App\Tests\Fixtures\Admin\FakeModule\FakeSearchProvider;
use App\Tests\Fixtures\Admin\FakeModule\FakeSecondNavigationItem;
use App\Tests\Fixtures\Admin\FakeModule\FakeTimelineActivityType;
use App\Tests\Fixtures\I18n\I18nProbeKernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The app kernel plus a fake module that uses every extension point the way a real one does: services
 * autoconfigured by their interface, nothing in Admin knowing about them (ADM-5). It also carries the
 * audit probes and the /admin/api routes.
 */
class AdminExtensionProbeKernel extends I18nProbeKernel
{
    protected const CACHE_SUFFIX = 'admin_probe';

    private const array SERVICES = [
        FakeNavigationItem::class,
        FakeSecondNavigationItem::class,
        FakeAdminOnlyNavigationItem::class,
        FakeDashboardWidget::class,
        FakeRecordTab::class,
        FakeRecordPanel::class,
        FakeRecordController::class,
        FakeJsonController::class,
        FakeAdminOnlyJsonController::class,
        FakeSearchProvider::class,
        FakeTimelineActivityType::class,
    ];

    protected function configureProbe(ContainerConfigurator $container): void
    {
        parent::configureProbe($container);

        $container->extension('framework', ['translator' => ['paths' => [__DIR__.'/translations']]]);
        $container->extension('twig', ['paths' => [__DIR__.'/templates' => 'admin_probe']]);

        $services = $container->services();
        foreach (self::SERVICES as $class) {
            $services->set($class)->autowire()->autoconfigure()->public();
        }
        foreach ([AuditProbeHandler::class, PlainProbeHandler::class] as $handler) {
            $services->set($handler)->autowire()->tag('messenger.message_handler', ['bus' => 'command.bus']);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        parent::configureRoutes($routes);

        $routes->import(__DIR__.'/FakeModule/FakeRecordController.php', 'attribute');
        $routes->import(__DIR__.'/FakeModule/FakeJsonController.php', 'attribute');
        $routes->import(__DIR__.'/FakeModule/FakeAdminOnlyJsonController.php', 'attribute');
    }
}
