<?php

declare(strict_types=1);

namespace App\Crm;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The token of a Location's opt-out link (G11) in every CRM mail: the Location's id and a signature over it, 44
 * URL-safe characters, built like `Venues\PreviewToken` with its own context, so a preview token is no opt-out token.
 * It names a Location, never an address, and stays valid as long as `APP_SECRET`: an opt-out link in an old mail
 * still works. Holding it only lets someone opt that Location out, so it is no secret worth hiding from logs.
 */
final readonly class OptOutToken
{
    public const string REQUIREMENT = '[A-Za-z0-9_-]{44}';

    private const string CONTEXT = 'crm-opt-out|';
    private const int MAC_BYTES = 16;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public function forCompany(string $companyId): string
    {
        $uuid = Uuid::fromString($companyId);

        return self::encode($uuid->toBinary()).self::encode($this->mac($uuid->toRfc4122()));
    }

    /**
     * The Location id a well-formed, correctly signed token names; null for anything else.
     */
    public function companyIdFrom(string $token): ?string
    {
        if (1 !== preg_match('/^'.self::REQUIREMENT.'$/D', $token)) {
            return null;
        }
        $bytes = base64_decode(strtr(substr($token, 0, 22), '-_', '+/'), true);
        if (false === $bytes || 16 !== \strlen($bytes)) {
            return null;
        }
        $id = Uuid::fromBinary($bytes)->toRfc4122();

        return hash_equals($this->forCompany($id), $token) ? $id : null;
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
