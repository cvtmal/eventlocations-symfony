<?php

declare(strict_types=1);

namespace App\Portal\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The token of the reminder mail's cancellation link (G9, `/konto/abo-kuendigen/{token}`): the term's id and a
 * signature over it, 44 URL-safe characters like `Venues\PreviewToken`, keyed with `kernel.secret` and its own
 * context. Nothing is stored; the term decides whether the link still does anything (cancelled already, ended).
 * Whoever holds the link may say "do not renew" for that one term, nothing else: no login, no other venue.
 *
 * `idFrom()` checks the signature before anything touches the database. The token is a secret: never log it
 * (`Shared\Http\SensitiveUrlScrubber`, the nginx log maps), never put it in an audit summary.
 */
final readonly class SubscriptionCancelToken
{
    public const string REQUIREMENT = '[A-Za-z0-9_-]{44}';

    private const string CONTEXT = 'subscription-cancel|';
    private const int MAC_BYTES = 16;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public function forId(string $subscriptionId): string
    {
        $uuid = Uuid::fromString($subscriptionId);

        return self::encode($uuid->toBinary()).self::encode($this->mac($uuid->toRfc4122()));
    }

    public function idFrom(string $token): ?string
    {
        if (1 !== preg_match('/^'.self::REQUIREMENT.'$/D', $token)) {
            return null;
        }
        $bytes = base64_decode(strtr(substr($token, 0, 22), '-_', '+/'), true);
        if (false === $bytes || 16 !== \strlen($bytes)) {
            return null;
        }
        $id = Uuid::fromBinary($bytes)->toRfc4122();

        // The whole token is compared, so a second spelling of the same bytes is no valid token.
        return hash_equals($this->forId($id), $token) ? $id : null;
    }

    private function mac(string $id): string
    {
        return substr(hash_hmac('sha256', self::CONTEXT.$id, $this->secret, true), 0, self::MAC_BYTES);
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
