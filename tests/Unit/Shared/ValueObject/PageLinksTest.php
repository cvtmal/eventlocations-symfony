<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\ValueObject;

use App\Shared\ValueObject\PageLinks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PageLinksTest extends TestCase
{
    /**
     * @param list<int|null> $expected
     */
    #[DataProvider('windows')]
    public function test_links_around_the_current_page(int $page, int $lastPage, array $expected): void
    {
        self::assertSame($expected, PageLinks::around($page, $lastPage));
    }

    /**
     * @return iterable<string, array{int, int, list<int|null>}>
     */
    public static function windows(): iterable
    {
        yield 'single page' => [1, 1, [1]];
        yield 'two pages' => [1, 2, [1, 2]];
        yield 'first of 20' => [1, 20, [1, 2, 3, null, 20]];
        yield 'second of 20' => [2, 20, [1, 2, 3, null, 20]];
        yield 'one-page gap shows the page' => [4, 20, [1, 2, 3, 4, 5, null, 20]];
        yield 'two gaps' => [5, 20, [1, null, 4, 5, 6, null, 20]];
        yield 'last of 20' => [20, 20, [1, null, 18, 19, 20]];
        yield 'middle of 5' => [3, 5, [1, 2, 3, 4, 5]];
    }

    public function test_the_page_is_clamped_into_the_range(): void
    {
        self::assertSame([1, null, 18, 19, 20], PageLinks::around(25, 20));
        self::assertSame([1, 2, 3, null, 20], PageLinks::around(0, 20));
        self::assertSame([1], PageLinks::around(3, 0));
    }
}
