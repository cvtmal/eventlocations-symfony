<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Translation;

use App\Tests\Support\Translation\CatalogueParity;
use App\Tests\Support\Translation\KernelLocales;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * Every enabled locale has exactly the keys of the default locale in every app domain.
 * FrenchLocaleProbeTest proves the check reports a gap once a second locale exists.
 */
final class CatalogueParityTest extends KernelTestCase
{
    public function test_every_enabled_locale_has_the_keys_of_the_default_locale(): void
    {
        $container = self::getContainer();
        $locales = KernelLocales::enabled($container);
        $default = KernelLocales::default($container);
        $projectDir = $container->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);

        $translator = $container->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);

        self::assertContains($default, $locales);
        self::assertSame([], CatalogueParity::diff(
            $translator,
            $default,
            $locales,
            CatalogueParity::domainsIn([$projectDir.'/translations'], $default),
        ));
    }
}
