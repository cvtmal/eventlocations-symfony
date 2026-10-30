<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Messenger;

use App\Shared\Messenger\ResetClosedEntityManagerMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;

final class ResetClosedEntityManagerMiddlewareTest extends TestCase
{
    public function test_an_open_manager_is_kept(): void
    {
        $registry = $this->registry(open: true, expectedResets: 0);
        $next = self::countingMiddleware();

        (new ResetClosedEntityManagerMiddleware($registry))->handle(new Envelope(new \stdClass()), new StackMiddleware($next));

        self::assertSame(1, $next->calls);
    }

    public function test_a_closed_manager_is_reset_before_the_next_middleware(): void
    {
        $registry = $this->registry(open: false, expectedResets: 1);
        $next = self::countingMiddleware();

        (new ResetClosedEntityManagerMiddleware($registry))->handle(new Envelope(new \stdClass()), new StackMiddleware($next));

        self::assertSame(1, $next->calls);
    }

    /**
     * @return MiddlewareInterface&object{calls: int}
     */
    private static function countingMiddleware(): MiddlewareInterface
    {
        return new class implements MiddlewareInterface {
            public int $calls = 0;

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ++$this->calls;

                return $envelope;
            }
        };
    }

    private function registry(bool $open, int $expectedResets): ManagerRegistry
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn($open);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $registry->expects(self::exactly($expectedResets))->method('resetManager')->willReturn($manager);

        return $registry;
    }
}
