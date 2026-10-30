<?php

declare(strict_types=1);

namespace App\Tests\Support\Translation;

use PHPUnit\Framework\Assert;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reads %kernel.enabled_locales% and %kernel.default_locale% from a test container with their types asserted.
 */
final class KernelLocales
{
    /**
     * @return list<string>
     */
    public static function enabled(ContainerInterface $container): array
    {
        $locales = $container->getParameter('kernel.enabled_locales');
        Assert::assertIsArray($locales);

        $enabled = [];
        foreach ($locales as $locale) {
            Assert::assertIsString($locale);
            $enabled[] = $locale;
        }

        return $enabled;
    }

    public static function default(ContainerInterface $container): string
    {
        $locale = $container->getParameter('kernel.default_locale');
        Assert::assertIsString($locale);

        return $locale;
    }
}
