<?php

declare(strict_types=1);

namespace App\Tests\Unit\Portal;

use App\Portal\Security\SubscriptionCancelToken;
use App\Venues\PreviewToken;
use PHPUnit\Framework\TestCase;

/**
 * The cancellation link's token (G9): it names its term, only under its own secret and context.
 */
final class SubscriptionCancelTokenTest extends TestCase
{
    private const string ID = '019a1b2c-3d4e-7f60-8a9b-0c1d2e3f4a5b';

    public function test_a_token_names_its_term_and_is_url_safe(): void
    {
        $tokens = new SubscriptionCancelToken('secret-a');
        $token = $tokens->forId(self::ID);

        self::assertMatchesRegularExpression('/^'.SubscriptionCancelToken::REQUIREMENT.'$/D', $token);
        self::assertSame(self::ID, $tokens->idFrom($token));
    }

    public function test_another_secret_a_changed_character_or_a_preview_token_is_no_token(): void
    {
        $token = new SubscriptionCancelToken('secret-a')->forId(self::ID);
        $tokens = new SubscriptionCancelToken('secret-a');

        self::assertNull(new SubscriptionCancelToken('secret-b')->idFrom($token));
        self::assertNull($tokens->idFrom(substr($token, 0, -1).('A' === $token[43] ? 'B' : 'A')));
        self::assertNull($tokens->idFrom(new PreviewToken('secret-a')->forId(self::ID)), 'a preview link of the same id cancels nothing');
        self::assertNull($tokens->idFrom('kurz'));
        self::assertNull($tokens->idFrom(str_repeat('/', 44)));
    }
}
