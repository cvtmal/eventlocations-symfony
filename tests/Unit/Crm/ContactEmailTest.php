<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\ValueObject\ContactEmail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContactEmailTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function cases(): iterable
    {
        yield 'null' => [null, null];
        yield 'empty' => ['', null];
        yield 'blank' => ["  \t\n", null];
        yield 'trimmed and lower-cased' => ['  Anna.Muster@Example.CH ', 'anna.muster@example.ch'];
        yield 'non-ASCII upper case' => ['INFO@SCHLÖSSLI.CH', 'info@schlössli.ch'];
        yield 'already canonical' => ['info@example.ch', 'info@example.ch'];
    }

    #[DataProvider('cases')]
    public function test_it_normalises(?string $input, ?string $expected): void
    {
        self::assertSame($expected, ContactEmail::normalise($input));
    }
}
