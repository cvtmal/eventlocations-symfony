<?php

declare(strict_types=1);

namespace App\Inquiries;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The token of a venue's opt-out link in the mail of a brief to several venues (F13): the venue's public number and
 * a signature over it with its own context, so no other token (`FeedbackToken`, `Crm\OptOutToken`) opens this page.
 * It stays valid as long as `APP_SECRET`. Holding it lets someone switch briefs off for that venue, which the venue
 * switches on again on its contact card, nothing more, so like the other opt-out tokens it is not filtered from logs.
 */
final readonly class BriefOptOutToken
{
    public const string REQUIREMENT = '[1-9][0-9]{0,8}-[A-Za-z0-9_-]{22}';
    private const string CONTEXT = 'inquiry-brief-opt-out|';
    private const int MAC_BYTES = 16;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public function forVenue(int $venueNumber): string
    {
        $mac = substr(hash_hmac('sha256', self::CONTEXT.$venueNumber, $this->secret, true), 0, self::MAC_BYTES);

        return $venueNumber.'-'.rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    /**
     * The venue number a well-formed, correctly signed token names; null for anything else.
     */
    public function venueNumberFrom(string $token): ?int
    {
        if (1 !== preg_match('/^'.self::REQUIREMENT.'$/D', $token)) {
            return null;
        }
        $number = (int) strstr($token, '-', true);

        return hash_equals($this->forVenue($number), $token) ? $number : null;
    }
}
