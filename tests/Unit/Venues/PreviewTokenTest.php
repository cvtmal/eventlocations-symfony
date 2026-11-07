<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\PreviewToken;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class PreviewTokenTest extends TestCase
{
    private const string ID = '019a5c3e-7d10-7a2b-8c4d-5e6f708192a3';

    public function test_a_token_names_its_link_and_is_url_safe(): void
    {
        $tokens = new PreviewToken('secret-a');
        $token = $tokens->forId(self::ID);

        self::assertMatchesRegularExpression('/^'.PreviewToken::REQUIREMENT.'$/D', $token);
        self::assertSame(self::ID, $tokens->idFrom($token));
        self::assertSame($token, $tokens->forId(strtoupper(self::ID)), 'one link, one token');
    }

    public function test_another_secret_or_a_changed_character_is_no_token(): void
    {
        $token = new PreviewToken('secret-a')->forId(self::ID);
        $tokens = new PreviewToken('secret-a');

        self::assertNull(new PreviewToken('secret-b')->idFrom($token));
        for ($i = 0; $i < 44; ++$i) {
            $changed = $token;
            $changed[$i] = 'A' === $token[$i] ? 'B' : 'A';
            self::assertNull($tokens->idFrom($changed), "character {$i}");
        }
    }

    public function test_a_malformed_token_is_refused_before_any_work(): void
    {
        $tokens = new PreviewToken('secret-a');

        foreach (['', 'zu-kurz', str_repeat('A', 43), str_repeat('A', 45), str_repeat('A', 43).'=', str_repeat('A', 43)."\n", str_repeat('-', 44)] as $token) {
            self::assertNull($tokens->idFrom($token), var_export($token, true));
        }
    }

    public function test_tokens_of_different_links_differ_in_the_signature_too(): void
    {
        $tokens = new PreviewToken('secret-a');
        $a = $tokens->forId(self::ID);
        $b = $tokens->forId(Uuid::v7()->toRfc4122());

        self::assertNotSame(substr($a, 22), substr($b, 22));
        // The id part of one link with the signature of another is no token.
        self::assertNull($tokens->idFrom(substr($a, 0, 22).substr($b, 22)));
    }
}
