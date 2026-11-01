<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\ValueObject\Chf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChfTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function amounts(): iterable
    {
        yield 'whole francs' => ['1250', 125000];
        yield 'zero' => ['0', 0];
        yield 'leading zeros' => ['007', 700];
        yield 'one decimal' => ['12.5', 1250];
        yield 'two decimals' => ['12.05', 1205];
        yield 'apostrophe' => ["1'234.50", 123450];
        yield 'typographic apostrophe' => ['1’234.50', 123450];
        yield 'space' => ['1 234.50', 123450];
        yield 'no-break space' => ["1\u{00A0}234.50", 123450];
        yield 'surrounding space' => ['  99.90 ', 9990];
        // 0.1 + 0.2, 0.29 and 1.15 are not exact as floats; truncating float * 100 gives 28 and 114.
        yield 'the sum a float gets wrong' => ['0.3', 30];
        yield 'a value a float truncates' => ['0.29', 29];
        yield 'another value a float truncates' => ['1.15', 115];
        yield 'the limit' => ["99'999'999.99", Chf::MAX_RAPPEN];
    }

    #[DataProvider('amounts')]
    public function test_it_parses_without_a_float(string $text, int $rappen): void
    {
        self::assertSame($rappen, Chf::parse($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusals(): iterable
    {
        yield 'empty' => [''];
        yield 'only grouping' => ["'"];
        yield 'three decimals' => ['1.234'];
        yield 'negative' => ['-5'];
        yield 'comma' => ['12,50'];
        yield 'no digits before the point' => ['.50'];
        yield 'no digits after the point' => ['12.'];
        yield 'letters' => ['12 CHF'];
        yield 'exponent' => ['1e3'];
        yield 'one franc above the limit' => ['100000000'];
        yield 'one franc above the limit, with decimals' => ['100000000.00'];
        yield 'far above an int' => ['99999999999999999999'];
        yield 'line break' => ["12\n3"];
    }

    #[DataProvider('refusals')]
    public function test_it_refuses(string $text): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Chf::parse($text);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function formats(): iterable
    {
        yield 'zero' => [0, '0'];
        yield 'below a franc' => [5, '0.05'];
        yield 'below a thousand' => [99900, '999'];
        yield 'whole francs' => [125000, "1'250"];
        yield 'with Rappen' => [125050, "1'250.50"];
        yield 'millions' => [123456750, "1'234'567.50"];
        yield 'the limit' => [Chf::MAX_RAPPEN, "99'999'999.99"];
    }

    #[DataProvider('formats')]
    public function test_it_formats_whole_francs_without_decimals(int $rappen, string $text): void
    {
        self::assertSame($text, Chf::format($rappen));
    }
}
