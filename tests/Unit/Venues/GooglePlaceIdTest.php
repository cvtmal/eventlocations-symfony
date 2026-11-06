<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\ValueObject\GooglePlaceId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GooglePlaceIdTest extends TestCase
{
    public function test_reads_a_place_id(): void
    {
        self::assertSame('ChIJN1t_tDeuEmsRUsoyG83frY4', GooglePlaceId::fromInput('ChIJN1t_tDeuEmsRUsoyG83frY4')?->value);
    }

    public function test_trims_the_input(): void
    {
        self::assertSame('ChIJN1t_tDeuEmsRUsoyG83frY4', GooglePlaceId::fromInput("  ChIJN1t_tDeuEmsRUsoyG83frY4\n")?->value);
    }

    public function test_ten_characters_with_dash_and_underscore_are_a_place_id(): void
    {
        self::assertSame('Ab-_012345', GooglePlaceId::fromInput('Ab-_012345')?->value);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function empties(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'spaces only' => ['   '];
    }

    #[DataProvider('empties')]
    public function test_an_empty_input_is_no_place_id(?string $input): void
    {
        self::assertNull(GooglePlaceId::fromInput($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'inner space' => ['ChIJN1t tDeuEmsRU'];
        yield 'angle bracket' => ['ChIJN1t<tDeuEmsRU'];
        yield '9 characters' => ['ChIJN1t_t'];
        yield '256 characters' => [str_repeat('a', 256)];
        yield 'umlaut' => ['ChIJN1t_tDeuEmsRÜ'];
    }

    #[DataProvider('invalid')]
    public function test_refuses_anything_else(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GooglePlaceId::fromInput($input);
    }

    public function test_the_pattern_matches_the_longest_place_id(): void
    {
        self::assertSame(1, preg_match(GooglePlaceId::PATTERN, str_repeat('a', 255)));
    }

    public function test_the_maps_url_encodes_the_name_and_the_place_id(): void
    {
        self::assertSame(
            'https://www.google.com/maps/search/?api=1&query=Schloss%20%26%20G%C3%A4rten%20M%C3%BCnchenwiler&query_place_id=ChIJN1t_tDeuEmsRUsoyG83frY4',
            GooglePlaceId::mapsUrl('Schloss & Gärten Münchenwiler', 'ChIJN1t_tDeuEmsRUsoyG83frY4'),
        );
    }

    public function test_the_search_url_encodes_the_query(): void
    {
        self::assertSame(
            'https://www.google.com/maps/search/?api=1&query=Hof%20Nirgends%20Bern%20Schweiz',
            GooglePlaceId::searchUrl('Hof Nirgends Bern Schweiz'),
        );
    }
}
