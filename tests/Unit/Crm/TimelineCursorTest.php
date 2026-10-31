<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\ValueObject\ActivityDate;
use App\Crm\ValueObject\TimelineCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TimelineCursorTest extends TestCase
{
    private const string ID = '0199f0d0-0000-7000-8000-000000000001';

    /**
     * An entry can carry any date the composer accepts, and a cursor that does not parse restarts the list, so
     * "Mehr laden" would show the first page again for good.
     *
     * @return iterable<string, array{string}>
     */
    public static function dates(): iterable
    {
        yield 'today' => ['2026-10-31T14:05'];
        yield 'before ten digits' => ['1999-06-01T10:00'];
        yield 'before 1970' => ['1950-06-01T10:00'];
        yield 'the first accepted year' => ['1900-01-01T00:00'];
    }

    #[DataProvider('dates')]
    public function test_a_token_comes_back_as_the_same_cursor(string $date): void
    {
        $at = ActivityDate::parse($date);
        self::assertNotNull($at);

        $cursor = TimelineCursor::parse((new TimelineCursor($at, self::ID))->token());

        self::assertNotNull($cursor);
        self::assertSame($at->getTimestamp(), $cursor->at->getTimestamp());
        self::assertSame(self::ID, $cursor->id);
    }

    public function test_garbage_is_no_cursor(): void
    {
        foreach ([null, '', 'x', '1_2', '1234567890_'.self::ID."\n", self::ID, '12345678901_'.self::ID] as $token) {
            self::assertNull(TimelineCursor::parse($token), (string) $token);
        }
    }
}
