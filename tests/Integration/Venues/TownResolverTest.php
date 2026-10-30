<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Venues\Exception\UnresolvedTown;
use App\Venues\TownResolver;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ZIP + name (+ canton) to exactly one town, shared by the content import and the CMS (#24).
 */
final class TownResolverTest extends KernelTestCase
{
    use ImportsTownDataset;

    private TownResolver $resolver;
    private Connection $connection;

    protected function setUp(): void
    {
        $resolver = self::getContainer()->get(TownResolver::class);
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(TownResolver::class, $resolver);
        self::assertInstanceOf(Connection::class, $connection);
        $this->resolver = $resolver;
        $this->connection = $connection;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_resolves_one_town(): void
    {
        $town = $this->resolver->resolve('8001', 'Zürich', null);

        self::assertSame(['Zürich', 'zh'], [$town->name(), $town->canton()->code()]);
    }

    public function test_an_unknown_town_is_not_ambiguous(): void
    {
        $exception = $this->unresolved('8001', 'Atlantis', null);

        self::assertFalse($exception->isAmbiguous());
        self::assertSame(['8001 Atlantis', 0], [$exception->place(), $exception->townCount()]);
    }

    public function test_the_same_name_in_two_cantons_is_ambiguous_until_the_canton_is_given(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO towns (id, canton_code, zip, name, slug, bfs_number, lat, lng, population) VALUES ('019a0000-0000-7000-8000-00000000a001', 'ag', '8001', 'Zürich', 'zuerich-ag', 99001, 47.4, 8.1, 10)",
        );
        $this->connection->executeStatement("INSERT INTO town_zips (zip, town_id) VALUES ('8001', '019a0000-0000-7000-8000-00000000a001')");

        $exception = $this->unresolved('8001', 'Zürich', null);
        self::assertTrue($exception->isAmbiguous());
        self::assertSame(['8001 Zürich', 2], [$exception->place(), $exception->townCount()]);

        self::assertSame('ag', $this->resolver->resolve('8001', 'Zürich', 'ag')->canton()->code());
        self::assertSame('zh', $this->resolver->resolve('8001', 'Zürich', 'zh')->canton()->code());
    }

    private function unresolved(string $zip, string $name, ?string $canton): UnresolvedTown
    {
        try {
            $this->resolver->resolve($zip, $name, $canton);
        } catch (UnresolvedTown $exception) {
            return $exception;
        }
        self::fail('Expected UnresolvedTown.');
    }
}
