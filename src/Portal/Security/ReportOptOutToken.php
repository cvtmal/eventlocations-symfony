<?php

declare(strict_types=1);

namespace App\Portal\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The token of the monthly report's opt-out link (G10, `/konto/bericht-abbestellen/{token}`): the venue's id and a
 * signature over it, 44 URL-safe characters like `SubscriptionCancelToken`, keyed with `kernel.secret` and its own
 * context, so a cancel token of the same UUID is no opt-out token. Nothing is stored. Whoever holds the link may turn
 * that one venue's report off and on again, nothing else: no login, no other venue.
 *
 * `venueIdFrom()` checks the signature before anything touches the database. The token is a secret: never log it
 * (`Shared\Http\SensitiveUrlScrubber`, the nginx log maps), never put it in an audit summary.
 */
final readonly class ReportOptOutToken
{
    public const string REQUIREMENT = '[A-Za-z0-9_-]{44}';

    private const string CONTEXT = 'owner-report-optout|';
    private const int MAC_BYTES = 16;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public function forVenueId(string $venueId): string
    {
        $uuid = Uuid::fromString($venueId);

        return self::encode($uuid->toBinary()).self::encode($this->mac($uuid->toRfc4122()));
    }

    public function venueIdFrom(string $token): ?string
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
        return hash_equals($this->forVenueId($id), $token) ? $id : null;
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
