<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\I18n;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The probe kernel with French enabled: only configuration and a catalogue are added, no
 * code under src/ changes. framework.enabled_locales is a list node, so [fr] is appended
 * to the app's [de].
 */
final class FrenchProbeKernel extends I18nProbeKernel
{
    protected const CACHE_SUFFIX = 'i18n_probe_fr';

    protected function configureProbe(ContainerConfigurator $container): void
    {
        parent::configureProbe($container);

        $container->extension('framework', [
            'enabled_locales' => ['fr'],
            'translator' => ['paths' => [__DIR__.'/translations-fr']],
        ]);
    }
}
