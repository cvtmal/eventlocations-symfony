<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cms;

use App\Cms\ValueObject\Francs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrancsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}> typed, Rappen
     */
    public static function typed(): iterable
    {
        yield 'whole francs' => ['199', 19900];
        yield 'zero' => ['0', 0];
        yield 'one decimal is tenths' => ['199.5', 19950];
        yield 'two decimals' => ['199.50', 19950];
        yield 'five Rappen' => ['0.05', 5];
        yield 'a comma as the decimal point' => ['199,50', 19950];
        yield 'the Swiss grouping' => ["1'250.50", 125050];
        yield 'a typographic apostrophe' => ['1’250', 125000];
        yield 'a no-break space' => ["1\u{00A0}250", 125000];
        yield 'one group of thousands' => ["100'000", 10000000];
        yield 'two groups of thousands' => ["1'000'000", 100000000];
        yield 'leading zeros' => ['007', 700];
        yield 'eight digits' => ['99999999', 9999999900];
    }

    #[DataProvider('typed')]
    public function test_a_typed_amount_is_read_as_rappen(string $typed, int $rappen): void
    {
        self::assertSame($rappen, Francs::parse($typed));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function garbage(): iterable
    {
        yield 'empty' => [''];
        yield 'a space' => [' '];
        yield 'letters' => ['abc'];
        yield 'negative' => ['-5'];
        yield 'a plus sign' => ['+5'];
        yield 'three decimals' => ['1.234'];
        yield 'a trailing point' => ['199.'];
        yield 'no francs' => ['.5'];
        yield 'an exponent' => ['1e3'];
        yield 'two points' => ['199.5.5'];
        yield 'two commas' => ['1,2,3'];
        yield 'a plain space is no grouping' => ['199 50'];
        yield 'a space grouping' => ['1 250'];
        yield 'a misplaced grouping' => ["1'25"];
        yield 'three groups' => ["1'000'000'000"];
        yield 'nine plain digits' => ['123456789'];
        yield 'another script\'s digits' => ['١٢٣'];
        yield 'a trailing newline' => ["199\n"];
        yield 'invalid UTF-8' => ["19\xff"];
        yield 'a NUL' => ["199\0"];
    }

    #[DataProvider('garbage')]
    public function test_garbage_is_refused(string $typed): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Francs::parse($typed);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function shown(): iterable
    {
        yield 'zero' => [0, '0'];
        yield 'whole francs' => [19900, '199'];
        yield 'with Rappen' => [19950, '199.50'];
        yield 'five Rappen' => [5, '0.05'];
        yield 'no grouping' => [10000000, '100000'];
    }

    #[DataProvider('shown')]
    public function test_rappen_are_shown_as_the_text_that_reads_back_to_them(int $rappen, string $shown): void
    {
        self::assertSame($shown, Francs::format($rappen));
        self::assertSame($rappen, Francs::parse($shown));
    }
}
