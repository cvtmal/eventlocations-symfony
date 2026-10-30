<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\I18n;

use App\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The app kernel plus a demo route, its template and two probe catalogues (German only),
 * with its own cache directory. Proves the i18n setup without shipping a demo page.
 * MicroKernelTrait resolves configureContainer/configureRoutes on $this by reflection, so
 * these methods replace the trait's private ones (protected, so PHPStan sees them used);
 * they repeat its imports because the trait's getConfigDir() is private to App\Kernel.
 */
class I18nProbeKernel extends Kernel
{
    protected const CACHE_SUFFIX = 'i18n_probe';

    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/'.$this->environment.'_'.static::CACHE_SUFFIX;
    }

    public function getBuildDir(): string
    {
        return $this->getCacheDir();
    }

    protected function configureProbe(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['translator' => ['paths' => [__DIR__.'/translations']]]);
        $container->extension('twig', ['paths' => [__DIR__.'/templates' => 'i18n_probe']]);
        $container->services()->set(ProbeController::class)->autowire()->autoconfigure();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $configDir = $this->getProjectDir().'/config';
        $configGlob = preg_replace('{/config$}', '/{config}', $configDir);

        $container->import($configGlob.'/{packages}/*.{php,yaml}');
        $container->import($configGlob.'/{packages}/'.$this->environment.'/*.{php,yaml}');

        if (is_file($configDir.'/services.yaml')) {
            $container->import($configGlob.'/services.yaml');
            $container->import($configGlob.'/{services}_'.$this->environment.'.yaml');
        } else {
            $container->import($configGlob.'/{services}.php');
            $container->import($configGlob.'/{services}_'.$this->environment.'.php');
        }

        $this->configureProbe($container);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $configDir = $this->getProjectDir().'/config';
        $configGlob = preg_replace('{/config$}', '/{config}', $configDir);

        $routes->import($configGlob.'/{routes}/'.$this->environment.'/*.{php,yaml}');
        $routes->import($configGlob.'/{routes}/*.{php,yaml}');

        if (is_file($configDir.'/routes.yaml')) {
            $routes->import($configGlob.'/routes.yaml');
        } else {
            $routes->import($configGlob.'/{routes}.php');
        }

        if ($fileName = (new \ReflectionObject($this))->getFileName()) {
            $routes->import($fileName, 'attribute');
        }

        $routes->import(__DIR__.'/ProbeController.php', 'attribute');
    }
}
