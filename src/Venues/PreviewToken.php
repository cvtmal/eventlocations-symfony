<?php

declare(strict_types=1);

namespace App\Venues;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The token of a preview link (G5): the link's row id and a signature over it, 44 URL-safe
 * characters (`base64url(16 bytes of the UUID) . base64url(the first 16 bytes of an HMAC-SHA256)`),
 * keyed with `kernel.secret` like the `/konto` links (`Portal\Security\PortalLinkSigner`). Nothing
 * secret is stored, so staff can show a link and its QR code again at any time; the row decides
 * whether the link still answers (expiry, revocation). Changing `APP_SECRET` ends every link.
 *
 * `idFrom()` checks the signature before anything touches the database, so a guessed token costs no
 * query. The token is a secret: never log it, put it in an audit summary or a funnel row.
 */
final readonly class PreviewToken
{
    public const string REQUIREMENT = '[A-Za-z0-9_-]{44}';

    private const string CONTEXT = 'venue-preview|';
    private const int MAC_BYTES = 16;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public function forId(string $previewId): string
    {
        $uuid = Uuid::fromString($previewId);

        return self::encode($uuid->toBinary()).self::encode($this->mac($uuid->toRfc4122()));
    }

    /**
     * The row id a well-formed, correctly signed token names; null for anything else.
     */
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
