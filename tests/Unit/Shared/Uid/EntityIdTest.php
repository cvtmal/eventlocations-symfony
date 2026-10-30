<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Uid;

use App\Shared\Uid\EntityId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class EntityIdTest extends TestCase
{
    public function test_generate_returns_rfc4122_uuid_v7(): void
    {
        $id = EntityId::generate();

        $uuid = Uuid::fromString($id);

        self::assertInstanceOf(UuidV7::class, $uuid);
        self::assertSame($id, (string) $uuid);
    }

    public function test_generate_returns_unique_values(): void
    {
        self::assertNotSame(EntityId::generate(), EntityId::generate());
    }
}
