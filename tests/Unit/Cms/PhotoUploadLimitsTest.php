<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cms;

use App\Cms\ValueObject\PhotoUploadLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhotoUploadLimitsTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, bool, bool}>
     */
    public static function shapes(): iterable
    {
        yield '1:2 at 24 MP, at the limit' => [3464, 6928, false, false];
        yield 'one row above the limit' => [3464, 6929, false, true];
        yield '3:2 at 24 MP' => [6000, 4000, false, false];
        yield '3:1 panorama at 24 MP' => [8485, 2828, false, false];
        yield '1:2.5 at 24 MP' => [3098, 7745, false, true];
        yield '1:3 at 24 MP' => [2828, 8485, false, true];
        yield 'stored wide, turned to the limit' => [6928, 3464, true, false];
        yield 'stored wide, turned one row above the limit' => [6929, 3464, true, true];
        yield 'stored wide, turned to 1:3' => [8485, 2828, true, true];
        yield 'stored 1:3, turned wide: the stored rule' => [2828, 8485, true, true];
        yield 'stored 2:1, not turned: a landscape' => [6929, 3464, false, false];
    }

    #[DataProvider('shapes')]
    public function test_the_portrait_limit_reads_the_stored_and_the_turned_shape(int $width, int $height, bool $swapsAxes, bool $tooTall): void
    {
        self::assertSame($tooTall, self::limits()->isTooTall($width, $height, $swapsAxes));
    }

    public function test_a_fractional_ratio_is_compared_exactly(): void
    {
        $limits = new PhotoUploadLimits(2.5);

        self::assertFalse($limits->isTooTall(4000, 10000, false));
        self::assertTrue($limits->isTooTall(4000, 10001, false));
    }

    private static function limits(): PhotoUploadLimits
    {
        return new PhotoUploadLimits(2.0);
    }
}
