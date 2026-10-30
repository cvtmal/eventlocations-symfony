<?php

declare(strict_types=1);

namespace App\Tests\Unit\Favorites;

use App\Favorites\ValueObject\MerklisteCookie;
use PHPUnit\Framework\TestCase;

/**
 * Pins the constants `assets/js/merkliste.js` shares with the codec, and the timing its two-tab
 * guarantee rests on. The script's behaviour is tested in `admin-ui/tests/public/merkliste.test.js`.
 */
final class MerklisteScriptTest extends TestCase
{
    public function test_a_flight_is_over_before_another_tab_can_sync(): void
    {
        self::assertLessThanOrEqual(self::constant('SYNC_DELAY_MS'), self::constant('SYNC_TIMEOUT_MS') + 1000);
    }

    public function test_it_shares_the_limits_of_the_codec(): void
    {
        self::assertSame(MerklisteCookie::MAX, self::constant('MAX_ENTRIES'));
        self::assertSame(MerklisteCookie::MAX_AGE_SECONDS, self::constant('MAX_AGE_SECONDS'));
    }

    private static function constant(string $name): int
    {
        self::assertSame(1, preg_match('/const '.$name.' = (\d+);/', self::script(), $match), $name.' is declared.');

        return (int) $match[1];
    }

    private static function script(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/assets/js/merkliste.js');
    }
}
