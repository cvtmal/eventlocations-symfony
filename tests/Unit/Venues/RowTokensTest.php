<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\Exception\StaleEdit;
use App\Venues\RowTokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RowTokensTest extends TestCase
{
    public function test_the_same_second_passes(): void
    {
        $this->expectNotToPerformAssertions();

        RowTokens::assertFresh(new \DateTimeImmutable('@1793361600'), '1793361600', 7);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function staleTokens(): iterable
    {
        yield 'one second earlier' => ['1793361599'];
        yield 'one second later' => ['1793361601'];
        yield 'empty' => [''];
    }

    #[DataProvider('staleTokens')]
    public function test_another_second_is_stale(string $loadedAt): void
    {
        try {
            RowTokens::assertFresh(new \DateTimeImmutable('@1793361600'), $loadedAt, 7);
            self::fail('The token should be stale.');
        } catch (StaleEdit $stale) {
            self::assertSame(7, $stale->venueNumber);
        }
    }

    public function test_sql_reads_whole_seconds(): void
    {
        self::assertSame('EXTRACT(EPOCH FROM p.updated_at)::bigint', RowTokens::sql('p.updated_at'));
    }
}
