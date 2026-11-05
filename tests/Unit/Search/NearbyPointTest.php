<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Search\ValueObject\NearbyPoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NearbyPointTest extends TestCase
{
    public function test_a_point_is_rounded_to_two_decimals(): void
    {
        $point = NearbyPoint::fromQuery('47.3769', '8.5417');

        self::assertNotNull($point);
        self::assertSame(47.38, $point->lat);
        self::assertSame(8.54, $point->lng);
        self::assertSame(['lat' => '47.38', 'lng' => '8.54'], $point->toQuery());
    }

    #[DataProvider('refused')]
    public function test_a_value_that_does_not_parse_or_lies_outside_the_box_is_refused(mixed $lat, mixed $lng): void
    {
        self::assertNull(NearbyPoint::fromQuery($lat, $lng));
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function refused(): iterable
    {
        yield 'null' => [null, '8.54'];
        yield 'an array' => [['47'], '8.54'];
        yield 'text' => ['abc', '8.54'];
        yield 'an exponent' => ['1e3', '8.54'];
        yield 'a comma' => ['47,37', '8.54'];
        yield 'empty' => ['', '8.54'];
        yield 'empty lng' => ['47.37', ''];
        yield 'far north' => ['90', '8.54'];
        yield 'just south of the box' => ['45.49', '8.54'];
        yield 'just north of the box' => ['48.11', '8.54'];
        yield 'just west of the box' => ['47.0', '5.49'];
        yield 'just east of the box' => ['47.0', '10.91'];
    }

    #[DataProvider('accepted')]
    public function test_the_box_edges_are_inside(string $lat, string $lng): void
    {
        self::assertNotNull(NearbyPoint::fromQuery($lat, $lng));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function accepted(): iterable
    {
        yield 'south edge' => ['45.5', '8.54'];
        yield 'north edge' => ['48.1', '8.54'];
        yield 'west edge' => ['47', '5.5'];
        yield 'east edge' => ['47', '10.9'];
    }

    public function test_a_town_centre_is_kept_unrounded(): void
    {
        $point = NearbyPoint::town(47.2289, 8.6696);

        self::assertSame(47.2289, $point->lat);
        self::assertSame(8.6696, $point->lng);
    }
}
