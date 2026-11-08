<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\OptOutToken;
use App\Venues\PreviewToken;
use PHPUnit\Framework\TestCase;

final class OptOutTokenTest extends TestCase
{
    private const string ID = '019a5c3e-7d10-7a2b-8c4d-5e6f708192a3';

    public function test_a_token_names_its_location_and_is_url_safe(): void
    {
        $tokens = new OptOutToken('secret-a');
        $token = $tokens->forCompany(self::ID);

        self::assertMatchesRegularExpression('/^'.OptOutToken::REQUIREMENT.'$/D', $token);
        self::assertSame(self::ID, $tokens->companyIdFrom($token));
        self::assertNull(new OptOutToken('secret-b')->companyIdFrom($token));
    }

    public function test_a_changed_character_or_a_preview_token_of_the_same_id_is_no_token(): void
    {
        $tokens = new OptOutToken('secret-a');
        $token = $tokens->forCompany(self::ID);

        for ($i = 0; $i < 44; ++$i) {
            $changed = $token;
            $changed[$i] = 'A' === $token[$i] ? 'B' : 'A';
            self::assertNull($tokens->companyIdFrom($changed), "character {$i}");
        }
        self::assertNull($tokens->companyIdFrom(new PreviewToken('secret-a')->forId(self::ID)), 'another context');
        foreach (['', 'kurz', str_repeat('A', 43), str_repeat('A', 45)] as $malformed) {
            self::assertNull($tokens->companyIdFrom($malformed));
        }
    }
}
