<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Funnel\CampaignCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CampaignCodeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function codes(): iterable
    {
        yield 'letters and a dash' => ['zh-brief-nov', true];
        yield 'an underscore' => ['ag_mail_2026', true];
        yield 'a digit first' => ['2026-nov', true];
        yield 'one character' => ['a', true];
        yield '40 characters' => [str_repeat('a', 40), true];
        yield '41 characters' => [str_repeat('a', 41), false];
        yield 'empty' => ['', false];
        yield 'a dash first' => ['-zh', false];
        yield 'an underscore first' => ['_zh', false];
        yield 'a space' => ['zh brief', false];
        yield 'an address' => ['max.muster@example.com', false];
        yield 'an at sign only' => ['a@b', false];
        yield 'a dot' => ['zh.brief', false];
        yield 'a trailing newline' => ["zh\n", false];
        yield 'an umlaut' => ['zürich', false];
    }

    #[DataProvider('codes')]
    public function test_only_a_plain_code_is_valid(string $code, bool $valid): void
    {
        self::assertSame($valid, CampaignCode::isValid($code));
    }
}
