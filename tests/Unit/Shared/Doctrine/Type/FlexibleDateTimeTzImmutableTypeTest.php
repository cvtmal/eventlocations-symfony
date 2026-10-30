<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Doctrine\Type;

use App\Shared\Doctrine\Type\FlexibleDateTimeTzImmutableType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use PHPUnit\Framework\TestCase;

final class FlexibleDateTimeTzImmutableTypeTest extends TestCase
{
    private FlexibleDateTimeTzImmutableType $type;
    private PostgreSQLPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new FlexibleDateTimeTzImmutableType();
        $this->platform = new PostgreSQLPlatform();
    }

    /**
     * Typed loosely on purpose: the DBAL parent's conditional return type would
     * otherwise let static analysis "know" the result for literal inputs.
     */
    private function convert(mixed $value): ?\DateTimeImmutable
    {
        return $this->type->convertToPHPValue($value, $this->platform);
    }

    public function test_converts_value_without_microseconds(): void
    {
        $value = $this->convert('2026-10-01 12:34:56+02');

        self::assertNotNull($value);
        self::assertSame('2026-10-01T12:34:56+02:00', $value->format(\DATE_ATOM));
    }

    public function test_converts_value_with_microseconds(): void
    {
        $value = $this->convert('2026-10-01 12:34:56.789012+00');

        self::assertNotNull($value);
        self::assertSame('2026-10-01 12:34:56', $value->format('Y-m-d H:i:s'));
        self::assertSame('789012', $value->format('u'));
    }

    public function test_returns_null_for_null(): void
    {
        self::assertNull($this->convert(null));
    }

    public function test_passes_datetime_immutable_through(): void
    {
        $dateTime = new \DateTimeImmutable('2026-10-01 12:00:00+00:00');

        self::assertSame($dateTime, $this->convert($dateTime));
    }

    public function test_throws_invalid_format_for_garbage(): void
    {
        $this->expectException(InvalidFormat::class);

        $this->convert('not a date');
    }
}
