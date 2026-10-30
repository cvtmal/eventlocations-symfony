<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\ValueObject\TownText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TownTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function valid(): iterable
    {
        yield 'zip and name' => ['8706 Meilen', '8706', 'Meilen'];
        yield 'umlaut' => ['8005 Zürich', '8005', 'Zürich'];
        yield 'double spaces' => ['8706  Meilen', '8706', 'Meilen'];
        yield 'spaces inside the name' => ['2300 La  Chaux-de-Fonds', '2300', 'La Chaux-de-Fonds'];
        yield 'a trailing newline' => ["8706 Meilen\n", '8706', 'Meilen'];
        yield 'a tab after the zip' => ["8706\tMeilen", '8706', 'Meilen'];
    }

    #[DataProvider('valid')]
    public function test_it_parses(string $text, string $zip, string $name): void
    {
        $town = TownText::parse($text);

        self::assertNotNull($town);
        self::assertSame($zip, $town->zip);
        self::assertSame($name, $town->name);
        self::assertSame($zip.' '.$name, (string) $town);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'no zip' => ['Meilen'];
        yield 'five-digit zip' => ['87060 Meilen'];
        yield 'three-digit zip' => ['870 Meilen'];
        yield 'zip alone' => ['8706'];
        yield 'zip and blanks' => ['8706   '];
        yield 'zip glued to the name' => ['8706Meilen'];
        yield 'name first' => ['Meilen 8706'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalid')]
    public function test_it_refuses(string $text): void
    {
        self::assertNull(TownText::parse($text));
    }

    public function test_format_is_the_inverse_of_parse(): void
    {
        self::assertSame('8706 Meilen', TownText::format('8706', 'Meilen'));
        self::assertEquals(TownText::parse('8706 Meilen'), TownText::parse(TownText::format('8706', 'Meilen')));
    }
}
