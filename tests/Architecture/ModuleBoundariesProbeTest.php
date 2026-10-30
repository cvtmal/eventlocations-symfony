<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Proves the module rules actually fire. The app has no module code yet, so a mis-wired rule set
 * (a wrong MODULES regex, a rule class missing from phpstan.neon.dist) would pass `make phpstan`
 * silently. This runs PHPStan with ModuleBoundariesTest against two probe modules under
 * Fixtures/ModuleBoundaries: the allowed edge Venues → Geo must stay clean, the forbidden edge
 * Geo → Venues must be the one and only error.
 */
final class ModuleBoundariesProbeTest extends TestCase
{
    public function test_a_forbidden_module_dependency_fails_phpstan(): void
    {
        $process = new Process(
            ['php', 'vendor/bin/phpstan', 'analyse', '-c', 'tests/Architecture/Fixtures/phpstan-module-boundaries.neon', '--error-format=json', '--no-progress', '--memory-limit=1G'],
            \dirname(__DIR__, 2),
        );
        $process->run();
        $output = $process->getOutput();

        self::assertNotSame(0, $process->getExitCode(), 'PHPStan passed although Geo depends on Venues: '.$output.$process->getErrorOutput());

        /** @var array{totals: array{file_errors: int}, files: array<string, array{messages: list<array{message: string}>}>} $result */
        $result = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(1, $result['totals']['file_errors'], 'Expected exactly the Geo → Venues error: '.$output);
        $files = array_keys($result['files']);
        self::assertCount(1, $files, $output);

        $file = $files[0];
        self::assertStringEndsWith('Geo/ReachesIntoVenues.php', $file, $output);
        self::assertStringContainsString('App\Venues\Probe\VenueCard', $result['files'][$file]['messages'][0]['message'], $output);
    }
}
