<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries;

use App\Inquiries\BriefOptOutToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F13: the venue's opt-out link of briefs carries its number and a signature over it.
 */
final class BriefOptOutTokenTest extends TestCase
{
    private const SECRET = 'a secret';

    public function test_a_token_names_its_venue(): void
    {
        $tokens = new BriefOptOutToken(self::SECRET);
        $token = $tokens->forVenue(4711);

        self::assertMatchesRegularExpression('/^'.BriefOptOutToken::REQUIREMENT.'$/D', $token);
        self::assertStringStartsWith('4711-', $token);
        self::assertSame(4711, $tokens->venueNumberFrom($token));
        self::assertSame($token, $tokens->forVenue(4711), 'the token is stable');
        self::assertNotSame($token, $tokens->forVenue(4712));
    }

    public function test_a_tampered_signature_is_refused(): void
    {
        $tokens = new BriefOptOutToken(self::SECRET);
        $token = $tokens->forVenue(12);
        $last = $token[-1];
        $tampered = substr($token, 0, -1).('A' === $last ? 'B' : 'A');

        self::assertNull($tokens->venueNumberFrom($tampered));
    }

    public function test_another_number_with_the_same_signature_is_refused(): void
    {
        $tokens = new BriefOptOutToken(self::SECRET);
        $mac = substr($tokens->forVenue(12), \strlen('12-'));

        self::assertNull($tokens->venueNumberFrom('13-'.$mac));
    }

    public function test_a_token_of_another_secret_is_refused(): void
    {
        $token = (new BriefOptOutToken('another secret'))->forVenue(12);

        self::assertNull((new BriefOptOutToken(self::SECRET))->venueNumberFrom($token));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'number only' => ['12'];
        yield 'no number' => ['-AAAAAAAAAAAAAAAAAAAAAA'];
        yield 'leading zero' => ['012-AAAAAAAAAAAAAAAAAAAAAA'];
        yield 'short mac' => ['12-AAAA'];
        yield 'long mac' => ['12-AAAAAAAAAAAAAAAAAAAAAAA'];
        yield 'padding' => ['12-AAAAAAAAAAAAAAAAAAAA=='];
        yield 'trailing newline' => ["12-AAAAAAAAAAAAAAAAAAAAAA\n"];
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_token_is_refused(string $token): void
    {
        self::assertNull((new BriefOptOutToken(self::SECRET))->venueNumberFrom($token));
    }

    public function test_a_valid_token_with_a_trailing_newline_is_refused(): void
    {
        $tokens = new BriefOptOutToken(self::SECRET);

        self::assertNull($tokens->venueNumberFrom($tokens->forVenue(12)."\n"));
    }
}
