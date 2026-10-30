<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Translation;

use App\Tests\Support\Translation\KernelLocales;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * No enabled locale misses a translation key used in src/ or templates/ (debug:translation
 * --only-missing). Keys built at runtime are invisible to it; see .claude/rules/i18n.md.
 */
final class TranslationCompletenessTest extends KernelTestCase
{
    public function test_no_enabled_locale_misses_a_key_used_in_code(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        $failures = [];
        foreach (KernelLocales::enabled(self::getContainer()) as $locale) {
            $tester->run(['command' => 'debug:translation', 'locale' => $locale, '--only-missing' => true]);
            if (0 !== $tester->getStatusCode()) {
                $failures[$locale] = $tester->getDisplay();
            }
        }

        self::assertSame([], $failures, implode("\n", $failures));
    }
}
