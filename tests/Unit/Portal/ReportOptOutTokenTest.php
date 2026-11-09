<?php

declare(strict_types=1);

namespace App\Tests\Unit\Portal;

use App\Portal\Security\ReportOptOutToken;
use App\Portal\Security\SubscriptionCancelToken;
use PHPUnit\Framework\TestCase;

/**
 * The opt-out link's token (G10): it names its venue, only under its own secret and context.
 */
final class ReportOptOutTokenTest extends TestCase
{
    private const string ID = '019a1b2c-3d4e-7f60-8a9b-0c1d2e3f4a5b';

    public function test_a_token_names_its_venue_and_is_url_safe(): void
    {
        $tokens = new ReportOptOutToken('secret-a');
        $token = $tokens->forVenueId(self::ID);

        self::assertMatchesRegularExpression('/^'.ReportOptOutToken::REQUIREMENT.'$/D', $token);
        self::assertSame(self::ID, $tokens->venueIdFrom($token));
    }

    public function test_another_secret_a_changed_character_or_a_cancel_token_is_no_token(): void
    {
        $tokens = new ReportOptOutToken('secret-a');
        $token = $tokens->forVenueId(self::ID);

        self::assertNull(new ReportOptOutToken('secret-b')->venueIdFrom($token));
        self::assertNull($tokens->venueIdFrom(substr($token, 0, -1).('A' === $token[43] ? 'B' : 'A')));
        self::assertNull($tokens->venueIdFrom(new SubscriptionCancelToken('secret-a')->forId(self::ID)), 'a cancel link of the same id opts nothing out');
        self::assertNull($tokens->venueIdFrom('kurz'));
        self::assertNull($tokens->venueIdFrom(str_repeat('/', 44)));
    }
}
