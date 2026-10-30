<?php

declare(strict_types=1);

namespace App\Shared\Security;

use Symfony\Contracts\Service\ResetInterface;

/**
 * The response's CSP nonce, created only when something asks for it. Public pages never do:
 * they are cached (S10), and CSP3 §7.1 needs a value unique per transmitted policy, so they
 * send plain `script-src 'self'`. `/admin` is `no-store` and uses it through `csp_nonce()`.
 * One shared service, so main and sub-requests of a response agree.
 */
final class CspNonce implements ResetInterface
{
    private ?string $value = null;

    public function value(): string
    {
        return $this->value ??= base64_encode(random_bytes(16));
    }

    public function used(): bool
    {
        return null !== $this->value;
    }

    public function reset(): void
    {
        $this->value = null;
    }
}
