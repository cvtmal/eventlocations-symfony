<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\ValueObject\SheetText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SheetTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cells(): iterable
    {
        yield 'a zero-width space is dropped' => ["Froschkönig, Detligen\u{200B}", 'Froschkönig, Detligen'];
        yield 'a left-to-right mark and an override are dropped' => ["\u{200E}Hof \u{202D}Beispiel", 'Hof Beispiel'];
        yield 'a no-break space is a space' => ["Hof\u{00A0}Beispiel", 'Hof Beispiel'];
        yield 'a narrow no-break space is a space' => ["044\u{202F}000 00 00", '044 000 00 00'];
        yield 'a thin space is a space' => ["Hof\u{2009}Beispiel", 'Hof Beispiel'];
        yield 'whitespace is collapsed and trimmed' => ["  Hof \t\r\n  Beispiel  ", 'Hof Beispiel'];
        yield 'NFD comes out NFC' => ["Gasthof Lo\u{0308}wen", "Gasthof L\u{00F6}wen"];
        yield 'a NUL is dropped' => ["Hof\0Beispiel", 'HofBeispiel'];
        yield 'a BOM inside a cell is dropped' => ["\u{FEFF}Hof", 'Hof'];
        yield 'empty stays empty' => ["\u{200B} \u{00A0}", ''];
    }

    #[DataProvider('cells')]
    public function test_it_cleans_a_cell(string $cell, string $expected): void
    {
        self::assertSame($expected, SheetText::clean($cell));
    }

    public function test_invalid_utf8_does_not_break_the_cleaner(): void
    {
        self::assertSame('Hof?', SheetText::clean("Hof\xC3"));
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function websites(): iterable
    {
        yield 'none' => [null, null];
        yield 'blank' => [' ', null];
        yield 'with a scheme and www' => ['https://www.Beispielhof.example.ch/hochzeit', 'beispielhof.example.ch'];
        yield 'without a scheme' => ['beispielhof.example.ch/x?gclid=1', 'beispielhof.example.ch'];
        yield 'www without a scheme' => ['www.beispielhof.example.ch', 'beispielhof.example.ch'];
        yield 'http' => ['http://beispielhof.example.ch', 'beispielhof.example.ch'];
        yield 'a stray space' => ['https://beispielhof .example.ch', 'beispielhof.example.ch'];
        yield 'no host' => ['https://', null];
    }

    #[DataProvider('websites')]
    public function test_it_reads_the_host_of_a_website(?string $website, ?string $expected): void
    {
        self::assertSame($expected, SheetText::host($website));
    }
}
