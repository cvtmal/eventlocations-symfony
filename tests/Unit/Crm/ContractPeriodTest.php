<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\ValueObject\ContractPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * N12: the next period runs as long as the old one in calendar terms, so a day count does not drift over a leap year.
 */
final class ContractPeriodTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, string, string, string}>
     */
    public static function periods(): iterable
    {
        yield 'a calendar year' => ['2026-01-01', '2026-12-31', '2027-01-01', '2027-12-31'];
        yield 'into a leap year' => ['2026-03-01', '2027-02-28', '2027-03-01', '2028-02-29'];
        yield 'out of a leap year' => ['2027-03-01', '2028-02-29', '2028-03-01', '2029-02-28'];
        yield 'a calendar year over a leap day' => ['2028-01-01', '2028-12-31', '2029-01-01', '2029-12-31'];
        yield 'six months' => ['2026-01-01', '2026-06-30', '2026-07-01', '2026-12-31'];
        yield 'no start is one year' => [null, '2026-12-31', '2027-01-01', '2027-12-31'];
        yield 'no start, into a leap year' => [null, '2027-02-28', '2027-03-01', '2028-02-29'];
    }

    #[DataProvider('periods')]
    public function test_the_next_period(?string $start, string $end, string $nextStart, string $nextEnd): void
    {
        $period = ContractPeriod::after(null === $start ? null : new \DateTimeImmutable($start), new \DateTimeImmutable($end));

        self::assertSame($nextStart, $period->start->format('Y-m-d'));
        self::assertSame($nextEnd, $period->end->format('Y-m-d'));
    }
}
