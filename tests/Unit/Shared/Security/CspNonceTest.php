<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Security;

use App\Shared\Security\CspNonce;
use PHPUnit\Framework\TestCase;

final class CspNonceTest extends TestCase
{
    public function test_it_is_stable_within_a_response(): void
    {
        $nonce = new CspNonce();

        self::assertSame($nonce->value(), $nonce->value());
        self::assertSame(16, \strlen((string) base64_decode($nonce->value(), true)));
    }

    public function test_it_is_unused_until_something_asks_for_it(): void
    {
        $nonce = new CspNonce();

        self::assertFalse($nonce->used());
        $nonce->value();
        self::assertTrue($nonce->used());
    }

    public function test_reset_starts_a_new_response(): void
    {
        $nonce = new CspNonce();
        $first = $nonce->value();

        $nonce->reset();

        self::assertFalse($nonce->used());
        self::assertNotSame($first, $nonce->value());
    }
}
