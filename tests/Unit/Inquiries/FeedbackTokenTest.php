<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries;

use App\Crm\OptOutToken;
use App\Inquiries\FeedbackToken;
use PHPUnit\Framework\TestCase;

final class FeedbackTokenTest extends TestCase
{
    private const string ID = '019a5c3e-7d10-7a2b-8c4d-5e6f708192a3';

    public function test_a_token_names_its_inquiry_and_is_url_safe(): void
    {
        $tokens = new FeedbackToken('secret-a');
        $token = $tokens->forInquiry(self::ID);

        self::assertMatchesRegularExpression('/^'.FeedbackToken::REQUIREMENT.'$/D', $token);
        self::assertSame(self::ID, $tokens->inquiryIdFrom($token));
        self::assertNull(new FeedbackToken('secret-b')->inquiryIdFrom($token));
    }

    public function test_a_changed_character_or_a_crm_opt_out_token_of_the_same_id_is_no_token(): void
    {
        $tokens = new FeedbackToken('secret-a');
        $token = $tokens->forInquiry(self::ID);

        for ($i = 0; $i < 44; ++$i) {
            $changed = $token;
            $changed[$i] = 'A' === $token[$i] ? 'B' : 'A';
            self::assertNull($tokens->inquiryIdFrom($changed), "character {$i}");
        }
        self::assertNull($tokens->inquiryIdFrom(new OptOutToken('secret-a')->forCompany(self::ID)), 'another context');
        foreach (['', 'kurz', str_repeat('A', 43), str_repeat('A', 45)] as $malformed) {
            self::assertNull($tokens->inquiryIdFrom($malformed));
        }
    }
}
