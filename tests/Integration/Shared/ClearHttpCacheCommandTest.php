<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Console\ClearHttpCacheCommand;
use App\Shared\Http\HttpCacheClearer;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final class ClearHttpCacheCommandTest extends KernelTestCase
{
    /** @var list<string> */
    private array $temporaryStores = [];

    public function test_it_empties_the_store(): void
    {
        $kernel = self::bootKernel();
        $store = $kernel->getShareDir().'/http_cache';
        (new Filesystem())->dumpFile($store.'/md/ab/entry', 'cached');

        $tester = new CommandTester((new Application($kernel))->find('app:http-cache:clear'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertDirectoryDoesNotExist($store);
        self::assertStringContainsString('HTTP cache store emptied.', $tester->getDisplay());
    }

    public function test_the_guard_keeps_a_store_within_the_default_limits(): void
    {
        $kernel = self::bootKernel();
        $store = $kernel->getShareDir().'/http_cache';
        (new Filesystem())->dumpFile($store.'/md/ab/entry', 'cached');

        $tester = new CommandTester((new Application($kernel))->find('app:http-cache:clear'));
        $tester->execute(['--if-over-limit' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertFileExists($store.'/md/ab/entry');
        self::assertSame('', $tester->getDisplay(), 'Cron mails any output, 288 times a day.');

        (new Filesystem())->remove($store);
    }

    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function limits(): iterable
    {
        yield 'at both limits' => [10, 2, true];
        yield 'one byte over' => [9, 2, false];
        yield 'one file over' => [10, 1, false];
    }

    #[DataProvider('limits')]
    public function test_the_guard_clears_only_above_a_limit(int $maxBytes, int $maxFiles, bool $kept): void
    {
        $root = $this->temporaryStore();

        [$exit, $display] = $this->runCommand(new Filesystem(), $root, $maxBytes, $maxFiles, ifOverLimit: true);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame($kept, is_dir($root.'/http_cache'));

        if ($kept) {
            self::assertSame('', $display);

            return;
        }

        self::assertStringContainsString('HTTP cache store: 10 bytes, 2 files.', $display);
        self::assertStringContainsString('HTTP cache store emptied.', $display);
    }

    /**
     * #20's cron and rollback see only the exit code. The test container runs as root, so a
     * Filesystem that refuses the rename stands in for an unwritable store.
     */
    public function test_a_failed_clear_exits_non_zero_and_does_not_claim_success(): void
    {
        $root = $this->temporaryStore();
        $refusing = new class extends Filesystem {
            public function rename(string $origin, string $target, bool $overwrite = false): void
            {
                throw new IOException('Cannot rename.');
            }
        };

        [$exit, $display] = $this->runCommand($refusing, $root, 1, 1, ifOverLimit: false);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('could not be emptied', $display);
        self::assertStringNotContainsString('HTTP cache store emptied.', $display);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryStores as $root) {
            (new Filesystem())->remove($root);
        }

        parent::tearDown();
    }

    /**
     * A store of 10 bytes in 2 files.
     */
    private function temporaryStore(): string
    {
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        \assert(\is_string($projectDir));
        $root = $projectDir.'/var/test-http-cache-'.bin2hex(random_bytes(4));
        (new Filesystem())->dumpFile($root.'/http_cache/md/ab/entry', '12345');
        (new Filesystem())->dumpFile($root.'/http_cache/en/ab/body', '12345');
        $this->temporaryStores[] = $root;

        return $root;
    }

    /**
     * @return array{int, string} the exit code and the output
     */
    private function runCommand(Filesystem $filesystem, string $root, int $maxBytes, int $maxFiles, bool $ifOverLimit): array
    {
        $command = new ClearHttpCacheCommand(new HttpCacheClearer($root.'/http_cache', $filesystem, new NullLogger()), $maxBytes, $maxFiles);
        $output = new BufferedOutput();
        $exit = $command(new SymfonyStyle(new ArrayInput([]), $output), $ifOverLimit);

        return [$exit, $output->fetch()];
    }
}
