<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\ValueObject\Tier;
use PHPUnit\Framework\TestCase;

final class TierTest extends TestCase
{
    public function test_ranks_follow_the_case_order(): void
    {
        self::assertSame([1, 2, 3, 4, 5], array_map(static fn (Tier $tier): int => $tier->rank(), Tier::cases()));
        self::assertSame(Tier::BeliebteLocation, Tier::cases()[0]);
        self::assertSame(Tier::Basic, Tier::cases()[4]);
    }
}
