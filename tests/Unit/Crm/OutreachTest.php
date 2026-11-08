<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\ValueObject\ContactChannel;
use App\Crm\ValueObject\Outreach;
use App\Crm\ValueObject\OutreachCohort;
use PHPUnit\Framework\TestCase;

final class OutreachTest extends TestCase
{
    public function test_two_outreaches_are_equal_by_value_and_by_day(): void
    {
        $a = new Outreach('brief-2026-11', OutreachCohort::A, ContactChannel::Postal, sentOn: new \DateTimeImmutable('2026-11-10 00:00'));

        self::assertTrue($a->equals(new Outreach('brief-2026-11', OutreachCohort::A, ContactChannel::Postal, sentOn: new \DateTimeImmutable('2026-11-10 15:30'))));
        self::assertFalse($a->equals(new Outreach('brief-2026-11', OutreachCohort::B, ContactChannel::Postal, sentOn: new \DateTimeImmutable('2026-11-10'))));
        self::assertFalse($a->equals(new Outreach('brief-2026-11', OutreachCohort::A, ContactChannel::Postal)));
        self::assertTrue(new Outreach()->equals(new Outreach()));
    }
}
