<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Controller;

use App\Shared\Controller\HealthController;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class HealthControllerTest extends TestCase
{
    public function test_returns_ok_when_database_answers(): void
    {
        $connection = $this->createStub(Connection::class);

        $response = (new HealthController($connection))();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"ok"}', $response->getContent());
    }

    public function test_returns_503_when_database_query_fails(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willThrowException(new \RuntimeException('connection refused'));

        $response = (new HealthController($connection))();

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('{"status":"error"}', $response->getContent());
    }
}
