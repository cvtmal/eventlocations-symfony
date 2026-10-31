<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Http\HttpCacheClearer;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Log\Logger;

final class HttpCacheClearerTest extends KernelTestCase
{
    private string $root;
    private string $store;

    protected function setUp(): void
    {
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        \assert(\is_string($projectDir));
        $this->root = $projectDir.'/var/test-http-cache-'.bin2hex(random_bytes(4));
        $this->store = $this->root.'/http_cache';
        (new Filesystem())->mkdir($this->root);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
        parent::tearDown();
    }

    public function test_a_missing_store_is_no_error(): void
    {
        self::assertTrue($this->clearer()->clear());
        $this->clearer()->invalidate();

        self::assertSame([], $this->entries());
    }

    public function test_a_clear_removes_a_full_store(): void
    {
        (new Filesystem())->dumpFile($this->store.'/md/ab/cd/entry', 'cached');
        (new Filesystem())->dumpFile($this->store.'/en/ab/cd/body', 'body');

        self::assertTrue($this->clearer()->clear());

        self::assertSame([], $this->entries());
    }

    public function test_an_invalidation_removes_the_metadata_and_leaves_the_bodies_readable(): void
    {
        (new Filesystem())->dumpFile($this->store.'/md/ab/cd/entry', 'cached');
        (new Filesystem())->dumpFile($this->store.'/en/ab/cd/body', 'body');

        $this->clearer()->invalidate();

        self::assertDirectoryDoesNotExist($this->store.'/md');
        self::assertSame('body', file_get_contents($this->store.'/en/ab/cd/body'));
        self::assertSame(['http_cache'], $this->entries());
    }

    public function test_usage_counts_the_store_and_what_a_clear_left_behind(): void
    {
        (new Filesystem())->dumpFile($this->store.'/md/ab/cd/entry', '12345');
        (new Filesystem())->dumpFile($this->store.'/en/ab/cd/body', '1234567');
        (new Filesystem())->dumpFile($this->store.'.purged-0123456789ab/md/entry', '123');
        (new Filesystem())->dumpFile($this->root.'/unrelated', 'not counted');

        self::assertSame(['bytes' => 15, 'files' => 3], $this->clearer()->usage());
    }

    public function test_usage_of_a_missing_store_is_zero(): void
    {
        self::assertSame(['bytes' => 0, 'files' => 0], $this->clearer()->usage());
    }

    public function test_copies_an_earlier_clear_left_behind_are_removed(): void
    {
        (new Filesystem())->dumpFile($this->store.'.purged-0123456789ab/md/entry', 'old');
        (new Filesystem())->dumpFile($this->store.'.purged-ba9876543210/md/entry', 'old');
        (new Filesystem())->dumpFile($this->root.'/unrelated', 'kept');

        self::assertTrue($this->clearer()->clear());

        self::assertSame(['unrelated'], $this->entries());
    }

    /**
     * The test container runs as root, so a read-only directory cannot make the rename fail;
     * a Filesystem that refuses it stands in for the real failure.
     */
    public function test_a_failure_is_logged_without_its_message_and_never_thrown(): void
    {
        (new Filesystem())->dumpFile($this->store.'/md/entry', 'cached');
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        $refusing = new class extends Filesystem {
            public function rename(string $origin, string $target, bool $overwrite = false): void
            {
                throw new IOException('Cannot rename /secret/path.');
            }
        };

        $clearer = new HttpCacheClearer($this->store, $refusing, new Logger(LogLevel::ERROR, $output));

        self::assertFalse($clearer->clear());

        $line = (string) stream_get_contents($output, offset: 0);
        self::assertStringContainsString('[error] HTTP cache clear failed (Symfony\Component\Filesystem\Exception\IOException at '.__FILE__.':', $line);
        self::assertStringNotContainsString('secret', $line);
        self::assertSame(1, substr_count($line, "\n"));
    }

    /**
     * The path only: that prod's framework.http_cache reads the same one is checked by hand with
     * debug:container --env=prod, because when@test replaces the purger and no test boots prod.
     */
    public function test_the_service_clears_the_store_under_the_share_dir(): void
    {
        $shareDir = self::getContainer()->getParameter('kernel.share_dir');
        \assert(\is_string($shareDir));
        (new Filesystem())->dumpFile($shareDir.'/http_cache/md/entry', 'cached');

        $clearer = self::getContainer()->get(HttpCacheClearer::class);
        self::assertInstanceOf(HttpCacheClearer::class, $clearer);
        $clearer->clear();

        self::assertDirectoryDoesNotExist($shareDir.'/http_cache');
    }

    private function clearer(): HttpCacheClearer
    {
        return new HttpCacheClearer($this->store, new Filesystem(), new NullLogger());
    }

    /**
     * @return list<string>
     */
    private function entries(): array
    {
        return array_values(array_diff(scandir($this->root) ?: [], ['.', '..']));
    }
}
