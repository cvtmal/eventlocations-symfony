<?php

declare(strict_types=1);

namespace App\Inquiries\Form;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The minimum fill time of IQ-3: `<unix>.<base64url HMAC-SHA256(unix, secret)>`, in a hidden
 * field. On a cached page the time is when that copy was rendered, not when the visitor
 * arrived, which only makes the check more lenient for people; a bot that fetches fresh and
 * posts at once is still caught. For the same reason there is no maximum age.
 */
final readonly class FillTimeToken
{
    public const MINIMUM_SECONDS = 3;

    public function __construct(
        private ClockInterface $clock,
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public function issue(): string
    {
        $unix = (string) $this->clock->now()->getTimestamp();

        return $unix.'.'.$this->signature($unix);
    }

    public function isHuman(?string $token): bool
    {
        if (null === $token || 1 !== preg_match('/^(\d{1,12})\.([A-Za-z0-9_-]{43})$/D', $token, $parts)) {
            return false;
        }
        if (!hash_equals($this->signature($parts[1]), $parts[2])) {
            return false;
        }

        return $this->clock->now()->getTimestamp() - (int) $parts[1] >= self::MINIMUM_SECONDS;
    }

    private function signature(string $unix): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $unix, $this->secret, true)), '+/', '-_'), '=');
    }
}
