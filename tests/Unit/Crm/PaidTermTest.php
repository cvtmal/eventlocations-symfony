<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\ValueObject\PaidTerm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The term a deal's payment buys (G9): the contract period, completed to one year from what is known.
 */
final class PaidTermTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string, ?string, string, string}>
     */
    public static function terms(): iterable
    {
        yield 'both dates' => ['2027-01-01', '2027-12-31', null, '2027-01-01', '2027-12-31'];
        yield 'only a start' => ['2027-03-01', null, null, '2027-03-01', '2028-02-29'];
        yield 'only an end' => [null, '2027-06-30', null, '2026-07-01', '2027-06-30'];
        yield 'no dates, never paid' => [null, null, null, '2026-11-08', '2027-11-07'];
        yield 'no dates, a running term' => [null, null, '2026-12-15', '2026-12-16', '2027-12-15'];
        yield 'no dates, a lapsed term' => [null, null, '2026-10-31', '2026-11-08', '2027-11-07'];
        yield 'no dates, the term ends today' => [null, null, '2026-11-08', '2026-11-09', '2027-11-08'];
    }

    #[DataProvider('terms')]
    public function test_the_term_is_the_contract_period_completed_to_a_year(?string $start, ?string $end, ?string $paidUntil, string $startsOn, string $endsOn): void
    {
        $term = PaidTerm::forDeal(self::day($start), self::day($end), self::day($paidUntil), new \DateTimeImmutable('2026-11-08'));

        self::assertSame([$startsOn, $endsOn], [$term->startsOn->format('Y-m-d'), $term->endsOn->format('Y-m-d')]);
    }

    private static function day(?string $day): ?\DateTimeImmutable
    {
        return null === $day ? null : new \DateTimeImmutable($day);
    }
}
