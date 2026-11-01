<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Doctrine;

use App\Shared\Doctrine\Middleware\ChannelBindingMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChannelBindingMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PGCHANNELBINDING');
    }

    public function test_the_variable_is_set_just_before_the_connection_and_not_earlier(): void
    {
        $seen = null;
        $inner = $this->createStub(Driver::class);
        $inner->method('connect')->willReturnCallback(function () use (&$seen): Connection {
            $seen = getenv('PGCHANNELBINDING');

            return $this->createStub(Connection::class);
        });

        $driver = (new ChannelBindingMiddleware('require'))->wrap($inner);
        self::assertFalse(getenv('PGCHANNELBINDING'));

        $driver->connect([]);

        self::assertSame('require', $seen);
    }

    public function test_it_sets_the_variable_again_on_every_connect(): void
    {
        $inner = $this->createStub(Driver::class);
        $inner->method('connect')->willReturn($this->createStub(Connection::class));
        $driver = (new ChannelBindingMiddleware('require'))->wrap($inner);

        $driver->connect([]);
        putenv('PGCHANNELBINDING'); // What php-fpm does at the end of a request.
        $driver->connect([]);

        self::assertSame('require', getenv('PGCHANNELBINDING'));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function noValue(): iterable
    {
        yield 'unset' => [null];
        yield 'empty' => [''];
    }

    #[DataProvider('noValue')]
    public function test_without_a_value_the_driver_is_left_alone(?string $value): void
    {
        $inner = $this->createStub(Driver::class);

        self::assertSame($inner, (new ChannelBindingMiddleware($value))->wrap($inner));
    }
}
