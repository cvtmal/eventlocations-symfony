<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries;

use App\Inquiries\ReadModel\QualifiedInquirySql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QualifiedInquirySqlTest extends TestCase
{
    public function test_a_counted_inquiry_is_qualified_and_unmarked(): void
    {
        self::assertSame("q.qualification = 'qualified' AND q.qualification_override IS NULL", QualifiedInquirySql::where('q'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badAliases(): iterable
    {
        yield 'an injection' => ['q.id) OR (1=1'];
        yield 'a trailing newline' => ["q\n"];
        yield 'upper case' => ['Q'];
        yield 'empty' => [''];
    }

    #[DataProvider('badAliases')]
    public function test_an_alias_that_is_not_plain_is_refused(string $alias): void
    {
        $this->expectException(\InvalidArgumentException::class);

        QualifiedInquirySql::where($alias);
    }
}
