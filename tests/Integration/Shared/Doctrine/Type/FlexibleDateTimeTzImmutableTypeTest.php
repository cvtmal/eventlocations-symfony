<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Doctrine\Type;

use App\Shared\Doctrine\Type\FlexibleDateTimeTzImmutableType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FlexibleDateTimeTzImmutableTypeTest extends KernelTestCase
{
    public function test_registered_type_is_the_flexible_type(): void
    {
        // DoctrineBundle registers custom types when the first connection is created.
        self::assertInstanceOf(Connection::class, self::getContainer()->get(Connection::class));

        self::assertInstanceOf(FlexibleDateTimeTzImmutableType::class, Type::getType(Types::DATETIMETZ_IMMUTABLE));
    }

    public function test_reads_postgres_timestamptz_with_microseconds(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        $raw = $connection->fetchOne("SELECT TIMESTAMPTZ '2026-10-01 12:34:56.789012+00'");
        $value = Type::getType(Types::DATETIMETZ_IMMUTABLE)->convertToPHPValue($raw, $connection->getDatabasePlatform());

        self::assertInstanceOf(\DateTimeImmutable::class, $value);
        self::assertSame('2026-10-01 12:34:56.789012', $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'));
    }
}
