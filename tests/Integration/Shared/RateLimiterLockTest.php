<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/**
 * #20 PD6: without a lock, parallel requests all read the same count (#14's review: 12 of 12 passed
 * a limit of 5). What counts is that a held lock really stops a consume, so this is the behaviour
 * and not the service definition: a test holds the limiter's lock and a second process, which has
 * to take the same lock, stays blocked until the test lets go. The lock's name is the limiter's id,
 * which RateLimiterFactory builds; if that ever changed the child would not block and this fails.
 */
final class RateLimiterLockTest extends KernelTestCase
{
    private const SECONDS_TO_REACH_THE_LOCK = 1;
    private const WAIT_SECONDS = 60;

    /** @var list<Process> */
    private array $children = [];

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            $child->stop(0);
        }
        parent::tearDown();
    }

    public function test_the_container_has_a_lock_factory(): void
    {
        self::assertInstanceOf(LockFactory::class, self::getContainer()->get('lock.factory'));
    }

    #[DataProvider('limiters')]
    public function test_a_held_lock_blocks_a_consume_in_another_process(string $limiter, string $pool): void
    {
        $key = Uuid::v7()->toRfc4122();
        $lockFactory = self::getContainer()->get('lock.factory');
        self::assertInstanceOf(LockFactory::class, $lockFactory);
        $lock = $lockFactory->createLock($limiter.'-'.$key);
        self::assertTrue($lock->acquire());

        $child = $this->consumeInAChild('limiter.'.$limiter, $key);

        try {
            $this->waitFor('ready', $child);
            // "ready" is printed one statement before the consume, so this is well past it.
            sleep(self::SECONDS_TO_REACH_THE_LOCK);

            self::assertTrue($child->isRunning(), 'The consume went past a held lock: '.$child->getErrorOutput());
            self::assertStringNotContainsString('consumed', $child->getOutput());
        } finally {
            $lock->release();
        }

        $child->wait();

        self::assertSame(0, $child->getExitCode(), $child->getErrorOutput());
        self::assertStringContainsString('consumed', $child->getOutput());

        $this->clearPool($pool);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function limiters(): iterable
    {
        yield 'inquiry_submit' => ['inquiry_submit', 'cache.inquiry_submit'];
        yield 'phone_reveal' => ['phone_reveal', 'cache.phone_reveal'];
    }

    private function consumeInAChild(string $service, string $key): Process
    {
        $child = new Process([\PHP_BINARY, \dirname(__DIR__, 2).'/Support/Shared/consume-limiter.php', $service, $key], timeout: self::WAIT_SECONDS);
        $child->start();
        $this->children[] = $child;

        return $child;
    }

    private function waitFor(string $text, Process $child): void
    {
        $deadline = microtime(true) + self::WAIT_SECONDS;
        while (!str_contains($child->getOutput(), $text)) {
            self::assertTrue($child->isRunning(), 'The child ended early: '.$child->getErrorOutput());
            self::assertLessThan($deadline, microtime(true), 'The child never reached the lock.');
            usleep(50_000);
        }
    }

    private function clearPool(string $id): void
    {
        $pool = self::getContainer()->get($id);
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }
}
