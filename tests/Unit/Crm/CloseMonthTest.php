<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\ValueObject\Chf;
use App\Crm\ValueObject\CloseMonth;
use App\Crm\ValueObject\DealProperty;
use PHPUnit\Framework\TestCase;

/**
 * What a board or list shows of a month and a stored amount or day (D12, N4).
 */
final class CloseMonthTest extends TestCase
{
    public function test_a_month_has_a_german_name(): void
    {
        self::assertSame('November 2026', CloseMonth::label('2026-11'));
        self::assertSame('März 2027', CloseMonth::label('2027-03'));
    }

    public function test_the_shape_takes_only_a_real_month(): void
    {
        self::assertSame(1, preg_match(CloseMonth::SHAPE, '2026-12'));
        self::assertSame(0, preg_match(CloseMonth::SHAPE, '2026-13'));
        self::assertSame(0, preg_match(CloseMonth::SHAPE, '26-1'));
    }

    public function test_a_row_shows_an_amount_and_a_day_and_leaves_the_rest_to_its_options(): void
    {
        self::assertSame("CHF 1'250.50", DealProperty::Amount->display('125050'));
        self::assertSame('31.12.2027', DealProperty::ContractEnd->display('2027-12-31'));
        self::assertNull(DealProperty::Owner->display('some-id'));
        self::assertNull(DealProperty::Amount->display(null));
    }

    public function test_a_spreadsheet_gets_a_plain_number(): void
    {
        self::assertSame('1234567.50', Chf::plain(123456750));
        self::assertSame('0.05', Chf::plain(5));
    }
}
