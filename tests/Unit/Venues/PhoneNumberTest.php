<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\ValueObject\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneNumberTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function numbers(): iterable
    {
        yield 'landline' => ['+41441234567', '044 123 45 67'];
        yield 'mobile' => ['+41791234567', '079 123 45 67'];
        yield 'free number' => ['+41800123456', '0800 123 456'];
        yield 'premium number' => ['+41900123456', '0900 123 456'];
        yield 'other country' => ['+4930123456', '+4930123456'];
        yield 'swiss with wrong length' => ['+4144123456', '+4144123456'];
    }

    #[DataProvider('numbers')]
    public function test_display_form(string $e164, string $display): void
    {
        $number = PhoneNumber::fromE164($e164);

        self::assertSame($display, $number->display());
        self::assertSame($e164, $number->e164());
    }
}
