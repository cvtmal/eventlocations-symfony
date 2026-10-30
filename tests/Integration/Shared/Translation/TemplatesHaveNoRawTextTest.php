<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Translation;

use App\Tests\Support\Twig\RawTextScanner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
use Twig\Environment;

/**
 * No template under templates/ shows text that bypasses the translator (GEN-1). The
 * baseline lists the exact texts of templates written before #2; it only shrinks, and an
 * entry that no longer occurs fails the test.
 */
final class TemplatesHaveNoRawTextTest extends KernelTestCase
{
    private const BASELINE = [
        // Removed by #7 when the real homepage replaces the placeholder.
        'site/placeholder.html.twig' => ['Hochzeitslocation'],
    ];

    public function test_templates_have_no_untranslated_text(): void
    {
        $container = self::getContainer();
        $twig = $container->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $projectDir = $container->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);

        $scanner = new RawTextScanner($twig);
        $mismatches = [];
        $seen = [];

        foreach ((new Finder())->files()->in($projectDir.'/templates')->name('*.twig')->sortByName() as $file) {
            $name = str_replace('\\', '/', $file->getRelativePathname());
            $seen[$name] = true;

            $expected = self::BASELINE[$name] ?? [];
            $found = $scanner->scan($file->getContents(), $name);
            if ($found !== $expected) {
                $mismatches[] = \sprintf('%s: found %s, allowed %s', $name, json_encode($found, \JSON_UNESCAPED_UNICODE), json_encode($expected, \JSON_UNESCAPED_UNICODE));
            }
        }

        foreach (array_keys(self::BASELINE) as $name) {
            if (!isset($seen[$name])) {
                $mismatches[] = \sprintf('%s: listed in the baseline but no such template exists; remove the entry', $name);
            }
        }

        self::assertSame([], $mismatches, implode("\n", $mismatches));
    }
}
