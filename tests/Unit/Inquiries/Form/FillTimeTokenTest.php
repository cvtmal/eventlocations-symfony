<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries\Form;

use App\Inquiries\Form\FillTimeToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class FillTimeTokenTest extends TestCase
{
    private const ISSUED_AT = '2026-06-01 12:00:00 UTC';

    public function test_a_token_is_too_fresh_until_three_seconds_have_passed(): void
    {
        $clock = new MockClock(self::ISSUED_AT);
        $tokens = new FillTimeToken($clock, 'secret');
        $token = $tokens->issue();

        self::assertFalse($tokens->isHuman($token));
        $clock->sleep(2);
        self::assertFalse($tokens->isHuman($token));
        $clock->sleep(1);
        self::assertTrue($tokens->isHuman($token));
    }

    public function test_an_old_token_is_accepted(): void
    {
        $clock = new MockClock(self::ISSUED_AT);
        $tokens = new FillTimeToken($clock, 'secret');
        $token = $tokens->issue();

        $clock->sleep(30 * 86400);

        self::assertTrue($tokens->isHuman($token));
    }

    public function test_a_token_from_the_future_is_refused(): void
    {
        $clock = new MockClock(self::ISSUED_AT);
        $later = (new FillTimeToken(new MockClock('2026-06-01 13:00:00 UTC'), 'secret'))->issue();

        self::assertFalse((new FillTimeToken($clock, 'secret'))->isHuman($later));
    }

    public function test_a_changed_time_breaks_the_signature(): void
    {
        $clock = new MockClock(self::ISSUED_AT);
        $tokens = new FillTimeToken($clock, 'secret');
        [$unix, $signature] = explode('.', $tokens->issue());

        $clock->sleep(60);

        self::assertFalse($tokens->isHuman(((int) $unix - 1).'.'.$signature));
    }

    public function test_a_token_of_another_secret_is_refused(): void
    {
        $clock = new MockClock(self::ISSUED_AT);
        $foreign = (new FillTimeToken($clock, 'other'))->issue();

        $clock->sleep(60);

        self::assertFalse((new FillTimeToken($clock, 'secret'))->isHuman($foreign));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function malformed(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'no separator' => ['1234567890'];
        yield 'not a number' => ['abc.'.str_repeat('a', 43)];
        yield 'short signature' => ['1234567890.abc'];
        yield 'trailing newline' => ['1234567890.'.str_repeat('a', 43)."\n"];
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_token_is_refused(?string $token): void
    {
        self::assertFalse((new FillTimeToken(new MockClock(self::ISSUED_AT), 'secret'))->isHuman($token));
    }
}
