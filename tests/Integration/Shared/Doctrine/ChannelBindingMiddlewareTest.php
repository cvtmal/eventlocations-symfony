<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Doctrine;

use App\Shared\Doctrine\Middleware\ChannelBindingDriver;
use App\Shared\Doctrine\Middleware\ChannelBindingMiddleware;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The real libpq. Lane and CI Postgres run with ssl=off, so a connection that really sees the
 * setting is refused: that refusal is the proof it arrived.
 */
final class ChannelBindingMiddlewareTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        putenv('PGCHANNELBINDING');
        unset($_SERVER['PGCHANNELBINDING'], $_ENV['PGCHANNELBINDING']);
        parent::tearDown();
    }

    public function test_the_application_connection_runs_through_the_middleware(): void
    {
        $middlewares = $this->connection()->getConfiguration()->getMiddlewares();

        self::assertCount(1, array_filter($middlewares, static fn (object $m): bool => $m instanceof ChannelBindingMiddleware));
    }

    public function test_the_application_reads_the_variable_of_that_name(): void
    {
        // deploy/env.local.example sets this name; a typo in the Autowire would give null and
        // silently no channel binding, while the unit tests hand the value in themselves.
        $_SERVER['PGCHANNELBINDING'] = $_ENV['PGCHANNELBINDING'] = 'require';
        $middlewares = array_filter($this->connection()->getConfiguration()->getMiddlewares(), static fn (object $m): bool => $m instanceof ChannelBindingMiddleware);
        self::assertCount(1, $middlewares);

        $wrapped = array_values($middlewares)[0]->wrap($this->createStub(Driver::class));

        self::assertInstanceOf(ChannelBindingDriver::class, $wrapped);
    }

    public function test_the_setting_reaches_libpq(): void
    {
        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('channel binding required');

        $this->freshConnection('require')->getNativeConnection();
    }

    public function test_without_the_setting_the_connection_opens(): void
    {
        self::assertSame(1, $this->freshConnection(null)->fetchOne('SELECT 1'));
    }

    private function freshConnection(?string $channelBinding): Connection
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([new ChannelBindingMiddleware($channelBinding)]);

        // Not persistent: a reused connection never reaches libpq's connect.
        return DriverManager::getConnection(['persistent' => false] + $this->connection()->getParams(), $configuration);
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
