<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\ValueObject\Term;
use App\Venues\ValueObject\TermGroup;
use PHPUnit\Framework\TestCase;

final class TermTest extends TestCase
{
    public function test_translates_with_group_and_code(): void
    {
        $translator = new RecordingTranslator();

        self::assertSame('label', (new Term(TermGroup::Building, 'castle'))->trans($translator, 'de'));
        self::assertSame([['term.building.castle', [], 'venues', 'de']], $translator->calls);
    }
}
