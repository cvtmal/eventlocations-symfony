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

    public function test_the_guest_fit_reads_the_venue_and_its_rooms_with_the_given_parameter(): void
    {
        self::assertSame(
            '((x.capacity_max >= :n AND (x.capacity_min IS NULL OR x.capacity_min <= :n))'
            .' OR EXISTS (SELECT 1 FROM rooms cap_r WHERE cap_r.venue_id = x.id AND cap_r.capacity_max >= :n AND (cap_r.capacity_min IS NULL OR cap_r.capacity_min <= :n)))',
            VenueCapacity::guestsFitSql('x', 'n'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badParameters(): iterable
    {
        yield 'with a colon' => [':guests'];
        yield 'an injection' => ['g) OR (1=1'];
        yield 'a trailing newline' => ["guests\n"];
        yield 'empty' => [''];
    }

    #[DataProvider('badParameters')]
    public function test_a_parameter_name_that_is_not_plain_is_refused(string $param): void
    {
        $this->expectException(\InvalidArgumentException::class);

        VenueCapacity::guestsFitSql('v', $param);
    }

    public function test_the_guest_fit_refuses_a_bad_alias_too(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        VenueCapacity::guestsFitSql('cap_r', 'guests');
    }
}
