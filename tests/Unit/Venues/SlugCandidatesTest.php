<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\SlugAllocator;
use PHPUnit\Framework\TestCase;

final class SlugCandidatesTest extends TestCase
{
    public function test_umlauts_and_sharp_s_are_transliterated_the_german_way(): void
    {
        self::assertSame('schloss-baerengraben', SlugAllocator::base('Schloss Bärengraben'));
        self::assertSame('grosse-muehle', SlugAllocator::base('Grosse Mühle'));
        self::assertSame('strasse-am-see', SlugAllocator::base('Straße am See'));
        self::assertSame('', SlugAllocator::base('…'));
    }

    public function test_candidates_add_the_town_then_a_counter(): void
    {
        $candidates = [];
        foreach (SlugAllocator::candidates('Schloss Beispiel', 'zuerich') as $candidate) {
            $candidates[] = $candidate;
            if (4 === \count($candidates)) {
                break;
            }
        }

        self::assertSame(['schloss-beispiel', 'schloss-beispiel-zuerich', 'schloss-beispiel-zuerich-2', 'schloss-beispiel-zuerich-3'], $candidates);
    }

    public function test_without_a_town_the_counter_follows_the_name(): void
    {
        $candidates = [];
        foreach (SlugAllocator::candidates('Schloss Beispiel', null) as $candidate) {
            $candidates[] = $candidate;
            if (3 === \count($candidates)) {
                break;
            }
        }

        self::assertSame(['schloss-beispiel', 'schloss-beispiel-2', 'schloss-beispiel-3'], $candidates);
    }

    public function test_without_a_town_a_long_name_is_cut_for_the_counter(): void
    {
        $generator = SlugAllocator::candidates(trim(str_repeat('Langer Name ', 20)), null);
        $generator->next();
        $withCounter = $generator->current();

        self::assertLessThanOrEqual(160, \strlen($withCounter));
        self::assertStringEndsWith('-2', $withCounter);
        self::assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $withCounter);
    }

    public function test_a_long_name_is_cut_at_a_hyphen(): void
    {
        $name = trim(str_repeat('Langer Name ', 20));
        $base = SlugAllocator::base($name);

        self::assertLessThanOrEqual(160, \strlen($base));
        self::assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $base);
        self::assertStringEndsWith('-name', $base);

        $generator = SlugAllocator::candidates($name, 'st-gallen');
        $generator->next();
        $withTown = $generator->current();
        $generator->next();
        $withCounter = $generator->current();
        self::assertLessThanOrEqual(160, \strlen($withTown));
        self::assertStringEndsWith('-st-gallen', $withTown);
        self::assertLessThanOrEqual(160, \strlen($withCounter));
        self::assertStringEndsWith('-st-gallen-2', $withCounter);
        self::assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $withCounter);
    }

    public function test_an_empty_slug_gives_no_candidates(): void
    {
        self::assertSame([], iterator_to_array(SlugAllocator::candidates('!!!', 'bern')));
    }
}
