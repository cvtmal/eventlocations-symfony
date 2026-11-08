<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\VenueCapacity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VenueCapacityTest extends TestCase
{
    public function test_both_expressions_read_the_given_venue_alias(): void
    {
        self::assertStringContainsString('GREATEST(x.capacity_max,', VenueCapacity::maxSql('x'));
        self::assertStringContainsString('cap_r.venue_id = x.id', VenueCapacity::minSql('x'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badAliases(): iterable
    {
        yield 'the rooms alias of the expression' => ['cap_r'];
        yield 'an injection' => ['v.id) OR (1=1'];
        yield 'a trailing newline' => ["v\n"];
        yield 'empty' => [''];
    }

    #[DataProvider('badAliases')]
    public function test_an_alias_that_is_not_plain_is_refused(string $alias): void
    {
        $this->expectException(\InvalidArgumentException::class);

        VenueCapacity::maxSql($alias);
    }
}
