<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\ValueObject\PreviewShortCode;
use PHPUnit\Framework\TestCase;

final class PreviewShortCodeTest extends TestCase
{
    public function test_a_code_has_ten_unambiguous_signs(): void
    {
        $codes = [];
        for ($i = 0; $i < 200; ++$i) {
            $code = PreviewShortCode::generate();
            self::assertMatchesRegularExpression('/^[2-9A-HJKMNP-Z]{10}$/', $code);
            $codes[$code] = true;
        }
        self::assertCount(200, $codes);
        self::assertSame(31, \strlen(PreviewShortCode::ALPHABET));
    }

    public function test_a_typed_code_is_normalised_or_refused(): void
    {
        self::assertSame('K7M3QX9TPA', PreviewShortCode::normalise(' k7m3qx9tpa '));
        foreach (['', 'K7M3QX9TP', 'K7M3QX9TPAB', 'K7M3QX9TP0', 'K7M3QX9TPI', 'K7M3QX9TP.', 'K7M3 QX9TPA'] as $typed) {
            self::assertNull(PreviewShortCode::normalise($typed), var_export($typed, true));
        }
    }

    public function test_the_printed_form_is_lower_case_and_normalises_back(): void
    {
        $code = PreviewShortCode::generate();

        self::assertSame(strtolower($code), PreviewShortCode::display($code));
        self::assertMatchesRegularExpression('/^[2-9a-hjkmnp-z]{10}$/', PreviewShortCode::display($code));
        self::assertSame($code, PreviewShortCode::normalise(PreviewShortCode::display($code)));
    }

    public function test_no_excluded_look_alike_is_accepted(): void
    {
        foreach (['0', '1', 'i', 'l', 'o', 'I', 'L', 'O'] as $lookAlike) {
            self::assertNull(PreviewShortCode::normalise('k7m3qx9tp'.$lookAlike), $lookAlike);
        }
    }
}
