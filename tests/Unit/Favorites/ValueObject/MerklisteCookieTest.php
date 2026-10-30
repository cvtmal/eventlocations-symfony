<?php

declare(strict_types=1);

namespace App\Tests\Unit\Favorites\ValueObject;

use App\Favorites\ValueObject\MerklisteCookie;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

final class MerklisteCookieTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, list<int>}>
     */
    public static function values(): iterable
    {
        yield 'null' => [null, []];
        yield 'empty' => ['', []];
        yield 'keeps the order' => ['12,3,56', [12, 3, 56]];
        yield 'the marker of a script write' => ['1,2,', [1, 2]];
        yield 'only commas' => [',', []];
        yield 'letters' => ['abc,7', [7]];
        yield 'negative' => ['-1,7', [7]];
        yield 'zero' => ['0,7', [7]];
        yield 'leading zero' => ['05,7', [7]];
        yield 'decimal' => ['1.5,7', [7]];
        yield 'exponent' => ['1e3,7', [7]];
        yield 'leading space' => [' 7,8', [8]];
        yield 'trailing newline' => ["7\n,8", [8]];
        yield 'empty tokens' => [',,7,,', [7]];
        yield 'duplicates keep the first position' => ['5,7,5,8,7', [5, 7, 8]];
        yield 'largest integer' => ['2147483647', [2147483647]];
        yield 'above the integer range' => ['2147483648,7', [7]];
        yield 'ten digits above the range' => ['9999999999,7', [7]];
        yield 'eleven digits' => ['12345678901,7', [7]];
    }

    /**
     * @param list<int> $numbers
     */
    #[DataProvider('values')]
    public function test_parse(?string $value, array $numbers): void
    {
        self::assertSame($numbers, MerklisteCookie::parse($value)->numbers());
    }

    public function test_parse_keeps_the_first_100_numbers(): void
    {
        $cookie = MerklisteCookie::parse(implode(',', range(1, 101)));

        self::assertSame(range(1, 100), $cookie->numbers());
        self::assertTrue($cookie->isFull());
    }

    public function test_garbage_does_not_use_up_the_cap(): void
    {
        $cookie = MerklisteCookie::parse('abc,'.implode(',', range(1, 100)).',0');

        self::assertSame(range(1, 100), $cookie->numbers());
    }

    public function test_is_not_full_at_99(): void
    {
        self::assertFalse(MerklisteCookie::parse(implode(',', range(1, 99)))->isFull());
    }

    public function test_is_valid_number(): void
    {
        self::assertTrue(MerklisteCookie::isValidNumber(1));
        self::assertTrue(MerklisteCookie::isValidNumber(2147483647));
        self::assertFalse(MerklisteCookie::isValidNumber(0));
        self::assertFalse(MerklisteCookie::isValidNumber(-1));
        self::assertFalse(MerklisteCookie::isValidNumber(2147483648));
    }

    public function test_has(): void
    {
        $cookie = MerklisteCookie::parse('1,2');

        self::assertTrue($cookie->has(2));
        self::assertFalse($cookie->has(3));
    }

    public function test_toggled_adds_at_the_end(): void
    {
        self::assertSame([5, 1, 9], MerklisteCookie::parse('5,1')->toggled(9)->numbers());
    }

    public function test_toggled_removes(): void
    {
        self::assertSame([5, 9], MerklisteCookie::parse('5,1,9')->toggled(1)->numbers());
    }

    public function test_adding_again_moves_to_the_end(): void
    {
        self::assertSame([1, 9, 5], MerklisteCookie::parse('5,1,9')->toggled(5)->toggled(5)->numbers());
    }

    public function test_a_full_list_refuses_a_new_number(): void
    {
        $full = MerklisteCookie::parse(implode(',', range(1, 100)));

        self::assertSame(range(1, 100), $full->toggled(101)->numbers());
    }

    public function test_a_full_list_still_removes(): void
    {
        $full = MerklisteCookie::parse(implode(',', range(1, 100)));

        self::assertSame(range(2, 100), $full->toggled(1)->numbers());
    }

    public function test_without_removes_and_keeps_the_order(): void
    {
        self::assertSame([12, 56], MerklisteCookie::parse('12,3,56')->without(3)->numbers());
    }

    public function test_without_leaves_a_list_without_the_number_unchanged(): void
    {
        self::assertSame([12, 3], MerklisteCookie::parse('12,3')->without(99)->numbers());
    }

    public function test_without_is_idempotent(): void
    {
        $once = MerklisteCookie::parse('12,3')->without(12);

        self::assertSame($once->numbers(), $once->without(12)->numbers());
    }

    public function test_without_the_last_entry_renders_a_deletion(): void
    {
        self::assertStringContainsString('merkliste=deleted', (string) MerklisteCookie::parse('12')->without(12)->toCookie());
    }

    public function test_value_never_writes_the_marker(): void
    {
        self::assertSame('1,2', MerklisteCookie::parse('1,2,')->value());
    }

    public function test_only_keeps_the_saved_order(): void
    {
        self::assertSame([9, 1], MerklisteCookie::parse('9,5,1')->only([1, 9, 12])->numbers());
    }

    public function test_from_request_without_a_cookie(): void
    {
        self::assertSame([], MerklisteCookie::fromRequest(new Request())->numbers());
    }

    public function test_from_request_reads_the_cookie(): void
    {
        self::assertSame([1, 2], MerklisteCookie::fromRequest(new Request(cookies: ['merkliste' => '1,2']))->numbers());
    }

    public function test_from_request_treats_an_array_as_absent(): void
    {
        self::assertSame([], MerklisteCookie::fromRequest(new Request(cookies: ['merkliste' => ['a' => '1']]))->numbers());
    }

    public function test_to_cookie(): void
    {
        $cookie = MerklisteCookie::parse('1,2,3')->toCookie();

        self::assertSame('merkliste', $cookie->getName());
        self::assertSame('1,2,3', $cookie->getValue());
        self::assertSame('/', $cookie->getPath());
        self::assertNull($cookie->getDomain());
        self::assertFalse($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertStringContainsString('merkliste=1%2C2%2C3', (string) $cookie);
        self::assertEqualsWithDelta(time() + 34560000, $cookie->getExpiresTime(), 5);
    }

    public function test_an_empty_list_renders_a_deletion(): void
    {
        $cookie = MerklisteCookie::parse('')->toCookie();

        self::assertStringContainsString('merkliste=deleted', (string) $cookie);
        self::assertStringContainsString('Max-Age=0', (string) $cookie);
    }

    public function test_value_round_trip(): void
    {
        $cookie = MerklisteCookie::parse('3,1,2');

        self::assertSame('3,1,2', $cookie->value());
        self::assertSame($cookie->numbers(), MerklisteCookie::parse($cookie->value())->numbers());
    }
}
