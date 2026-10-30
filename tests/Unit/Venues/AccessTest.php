<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\ValueObject\Access;
use PHPUnit\Framework\TestCase;

final class AccessTest extends TestCase
{
    public function test_thresholds_match_eventlokale(): void
    {
        self::assertSame(1.0, Access::Station->maxDistanceKm());
        self::assertSame(10.0, Access::Highway->maxDistanceKm());
        self::assertSame(30.0, Access::Airport->maxDistanceKm());
    }

    public function test_every_case_has_a_positive_threshold(): void
    {
        foreach (Access::cases() as $access) {
            self::assertGreaterThan(0.0, $access->maxDistanceKm(), $access->value);
        }
    }
}
