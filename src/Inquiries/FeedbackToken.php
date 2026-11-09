<?php

declare(strict_types=1);

namespace App\Inquiries;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The token of an inquiry's feedback links (G16): the inquiry id and a signature over it, 44 URL-safe characters,
 * built like `Crm\OptOutToken` with its own context, so neither token opens the other's page. It stays valid as long
 * as `APP_SECRET` and the inquiry (LEG-2). Holding it lets someone answer for that inquiry or stop the feedback mails
 * to its address, nothing more, so like the CRM opt-out token it is not filtered from logs.
 */
final readonly class FeedbackToken
{
    public const string REQUIREMENT = '[A-Za-z0-9_-]{44}';

    private const string CONTEXT = 'inquiry-feedback|';
    private const int MAC_BYTES = 16;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public function forInquiry(string $inquiryId): string
    {
        $uuid = Uuid::fromString($inquiryId);

        return self::encode($uuid->toBinary()).self::encode($this->mac($uuid->toRfc4122()));
    }

    /**
     * The inquiry id a well-formed, correctly signed token names; null for anything else.
     */
    public function inquiryIdFrom(string $token): ?string
    {
        if (1 !== preg_match('/^'.self::REQUIREMENT.'$/D', $token)) {
            return null;
        }
        $bytes = base64_decode(strtr(substr($token, 0, 22), '-_', '+/'), true);
        if (false === $bytes || 16 !== \strlen($bytes)) {
            return null;
        }
        $id = Uuid::fromBinary($bytes)->toRfc4122();

        return hash_equals($this->forInquiry($id), $token) ? $id : null;
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
