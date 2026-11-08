<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\Form\ReturnPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * P11: `return_to` is client input, and only a back-office path is a place to go back to.
 */
final class ReturnPathTest extends TestCase
{
    private const string FALLBACK = '/admin/crm/aufgaben';

    /**
     * @return iterable<string, array{string}>
     */
    public static function backOfficePaths(): iterable
    {
        yield 'a record, with its card' => ['/admin/crm/firmen/019a0000-0000-7000-8000-000000000000#aufgaben'];
        yield 'a list with a query' => ['/admin/crm/aufgaben?view=heute&page=2'];
        yield 'a venue tab' => ['/admin/locations/12/fotos'];
    }

    #[DataProvider('backOfficePaths')]
    public function test_a_back_office_path_passes(string $path): void
    {
        self::assertSame($path, ReturnPath::safe($path, self::FALLBACK));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function otherValues(): iterable
    {
        yield 'nothing' => [null];
        yield 'empty' => [''];
        yield 'an array' => [['/admin/crm']];
        yield 'another site' => ['https://evil.example/admin/crm'];
        yield 'protocol relative' => ['//evil.example/admin/'];
        yield 'protocol relative inside' => ['/admin//evil.example'];
        yield 'a backslash' => ['/admin/\\evil.example'];
        yield 'a public page' => ['/eventlokale'];
        yield 'only a prefix' => ['/administrator/x'];
        yield 'a line break' => ["/admin/x\r\nSet-Cookie: a=b"];
        yield 'invalid UTF-8' => ["/admin/\xC3\x28"];
    }

    #[DataProvider('otherValues')]
    public function test_anything_else_gives_the_fallback(mixed $value): void
    {
        self::assertSame(self::FALLBACK, ReturnPath::safe($value, self::FALLBACK));
    }
}
