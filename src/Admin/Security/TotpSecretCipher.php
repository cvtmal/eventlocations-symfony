<?php

declare(strict_types=1);

namespace App\Admin\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts TOTP secrets at rest with libsodium's secretbox (S12). The `v1:` prefix leaves room for a
 * key rotation: a later version can decrypt `v1` and re-encrypt.
 */
final class TotpSecretCipher
{
    private const string VERSION = 'v1:';

    private readonly string $key;

    public function __construct(
        #[Autowire(env: 'ADMIN_TOTP_KEY')]
        string $base64Key,
    ) {
        $key = base64_decode($base64Key, true);
        if (false === $key || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            throw new \InvalidArgumentException(\sprintf('ADMIN_TOTP_KEY must be the base64 of %d bytes.', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        }

        $this->key = $key;
    }

    public function encrypt(string $secret): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::VERSION.base64_encode($nonce.sodium_crypto_secretbox($secret, $nonce, $this->key));
    }

    public function decrypt(string $encrypted): string
    {
        if (!str_starts_with($encrypted, self::VERSION)) {
            throw new \RuntimeException('Unknown TOTP secret version.');
        }

        $raw = base64_decode(substr($encrypted, \strlen(self::VERSION)), true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Malformed TOTP secret.');
        }

        $secret = sodium_crypto_secretbox_open(
            substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );
        if (false === $secret) {
            throw new \RuntimeException('The TOTP secret cannot be decrypted with this key.');
        }

        return $secret;
    }
}
