<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Proves the module rules actually fire. A mis-wired rule set (a wrong MODULES regex, a rule class
 * missing from phpstan.neon.dist) would pass `make phpstan` silently. This runs PHPStan with
 * ModuleBoundariesTest against probe modules under Fixtures/ModuleBoundaries: the allowed edge
 * Venues → Geo must stay clean, the forbidden edges Geo → Venues and Cms → Crm must be the only errors.
 */
final class ModuleBoundariesProbeTest extends TestCase
{
    public function test_forbidden_module_dependencies_fail_phpstan(): void
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

        self::assertSame(2, $result['totals']['file_errors'], 'Expected exactly the Geo → Venues and Cms → Crm errors: '.$output);
        self::assertCount(2, $result['files'], $output);

        $messageFor = static function (string $suffix) use ($result, $output): string {
            foreach ($result['files'] as $file => $errors) {
                if (str_ends_with($file, $suffix)) {
                    return $errors['messages'][0]['message'];
                }
            }

            self::fail('No PHPStan error for '.$suffix.': '.$output);
        };

        self::assertStringContainsString('App\Venues\Probe\VenueCard', $messageFor('Geo/ReachesIntoVenues.php'), $output);
        self::assertStringContainsString('App\Crm\Probe\CompanyCard', $messageFor('Cms/ReachesIntoCrm.php'), $output);
    }
}
