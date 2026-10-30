<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Admin\Security\TotpSecretCipher;
use PHPUnit\Framework\TestCase;

final class TotpSecretCipherTest extends TestCase
{
    private const string SECRET = 'JBSWY3DPEHPK3PXP';

    public function test_round_trip(): void
    {
        $cipher = self::cipher();

        self::assertSame(self::SECRET, $cipher->decrypt($cipher->encrypt(self::SECRET)));
    }

    public function test_output_is_versioned_and_hides_the_secret(): void
    {
        $encrypted = self::cipher()->encrypt(self::SECRET);

        self::assertStringStartsWith('v1:', $encrypted);
        self::assertStringNotContainsString(self::SECRET, $encrypted);
    }

    public function test_each_encryption_uses_a_new_nonce(): void
    {
        $cipher = self::cipher();

        self::assertNotSame($cipher->encrypt(self::SECRET), $cipher->encrypt(self::SECRET));
    }

    public function test_a_key_of_the_wrong_length_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TotpSecretCipher(base64_encode('too short'));
    }

    public function test_a_tampered_ciphertext_is_rejected(): void
    {
        $cipher = self::cipher();
        $raw = (string) base64_decode(substr($cipher->encrypt(self::SECRET), 3), true);
        $raw[\strlen($raw) - 1] = \chr(\ord($raw[\strlen($raw) - 1]) ^ 1);

        $this->expectException(\RuntimeException::class);

        $cipher->decrypt('v1:'.base64_encode($raw));
    }

    public function test_another_key_cannot_decrypt(): void
    {
        $encrypted = self::cipher()->encrypt(self::SECRET);

        $this->expectException(\RuntimeException::class);

        (new TotpSecretCipher(base64_encode(str_repeat('b', 32))))->decrypt($encrypted);
    }

    public function test_an_unknown_version_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);

        self::cipher()->decrypt('v2:abcd');
    }

    private static function cipher(): TotpSecretCipher
    {
        return new TotpSecretCipher(base64_encode(str_repeat('a', 32)));
    }
}
