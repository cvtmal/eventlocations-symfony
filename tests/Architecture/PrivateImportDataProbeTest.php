<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Proves PrivateImportDataTest fires: PHPStan runs it over probe classes, and the controller that reads a source
 * record must be the only error while the handler that stores one stays clean.
 */
final class PrivateImportDataProbeTest extends TestCase
{
    public function test_a_controller_that_reads_the_import_data_fails_phpstan(): void
    {
        $process = new Process(
            ['php', 'vendor/bin/phpstan', 'analyse', '-c', 'tests/Architecture/Fixtures/phpstan-private-import-data.neon', '--error-format=json', '--no-progress', '--memory-limit=1G'],
            \dirname(__DIR__, 2),
        );
        $process->run();
        $output = $process->getOutput();

        self::assertNotSame(0, $process->getExitCode(), 'PHPStan passed although a controller reads a source record: '.$output.$process->getErrorOutput());

        /** @var array{totals: array{file_errors: int}, files: array<string, array{messages: list<array{message: string}>}>} $result */
        $result = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(1, $result['totals']['file_errors'], $output);
        $files = array_keys($result['files']);
        self::assertCount(1, $files, $output);
        self::assertStringEndsWith('Controller/ReadsSourceRecords.php', $files[0], $output);
        self::assertStringContainsString('should not depend on App\Venues\Entity\SourceRecord', $result['files'][$files[0]]['messages'][0]['message'], $output);
    }
}
