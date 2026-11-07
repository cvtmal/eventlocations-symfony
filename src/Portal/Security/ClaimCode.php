<?php

declare(strict_types=1);

namespace App\Portal\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The six-digit code of a claim (G6). Only its HMAC with `kernel.secret` and the challenge id is stored, so a
 * database copy reveals no code and a code is bound to its challenge.
 */
final readonly class ClaimCode
{
    public const string REQUIREMENT = '[0-9]{6}';

    private const string CONTEXT = 'claim-code|';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public function generate(): string
    {
        return str_pad((string) random_int(0, 999_999), 6, '0', \STR_PAD_LEFT);
    }

    public function hash(string $challengeId, string $code): string
    {
        return hash_hmac('sha256', self::CONTEXT.$challengeId.'|'.$code, $this->secret);
    }

    public function matches(string $hash, string $challengeId, string $code): bool
    {
        return 1 === preg_match('/^'.self::REQUIREMENT.'$/D', $code) && hash_equals($hash, $this->hash($challengeId, $code));
    }
}
