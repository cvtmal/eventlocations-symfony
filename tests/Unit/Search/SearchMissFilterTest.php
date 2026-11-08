<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Search\SearchMissFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * D45, D46: the zero-result log keeps at most 100 characters of the search text and nothing of a search whose text
 * holds an "@" or six or more digits in a row (spaces, dots, dashes and slashes may separate them).
 */
final class SearchMissFilterTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function contactData(): iterable
    {
        yield 'an e-mail address' => ['anna.muster@example.ch'];
        yield 'a bare @' => ['Apéro @ Seeufer'];
        yield 'a mobile number with spaces' => ['Rückruf 079 123 45 67'];
        yield 'a number with slash and dots' => ['044/123.45.67'];
        yield 'a number with dashes' => ['044-123-45-67'];
        yield 'an international number' => ['+41 44 123 45 67'];
        yield 'six digits in one run' => ['Kunde 123456'];
        yield 'six digits split by mixed separators' => ['12 . 34/56'];
        yield 'a full date' => ['Weihnachtsessen 12.12.2026'];
        yield 'a postcode next to a year (eight digits with one space)' => ['8001 2026'];
        yield 'a number with commas' => ['079,123,45,67'];
        yield 'a number with underscores' => ['079_123_45_67'];
        yield 'an area code in brackets' => ['(044) 123 45'];
        yield 'a fullwidth at sign' => ["anna\u{FF20}example.ch"];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function plainText(): iterable
    {
        yield 'a postcode' => ['8001'];
        yield 'a year' => ['2026'];
        yield 'a postcode with a town' => ['Seeblick 8001 Zürich'];
        yield 'numbers apart' => ['Saal für 120 Gäste in 8001'];
        yield 'five digits' => ['50-100 Personen'];
        yield 'plain words' => ['Schloss mit Garten'];
    }

    #[DataProvider('contactData')]
    public function test_a_text_that_looks_like_contact_data_is_not_logged(string $text): void
    {
        self::assertTrue(SearchMissFilter::looksLikeContactData($text));
        self::assertNull(SearchMissFilter::query(['canton' => 'zh', 'q' => $text]));
    }

    #[DataProvider('plainText')]
    public function test_a_plain_text_is_logged(string $text): void
    {
        self::assertFalse(SearchMissFilter::looksLikeContactData($text));
        self::assertSame('canton=zh&q='.urlencode($text), SearchMissFilter::query(['canton' => 'zh', 'q' => $text]));
    }

    public function test_a_number_that_crosses_the_cut_is_found_in_the_raw_text(): void
    {
        $raw = str_repeat('a', 95).' 079 12 34 56';
        $cut = mb_substr($raw, 0, 100);
        self::assertFalse(SearchMissFilter::looksLikeContactData($cut), 'the cut leaves five digits');

        self::assertNull(SearchMissFilter::query(['q' => $cut], $raw));
        self::assertSame('q=Schloss', SearchMissFilter::query(['q' => 'Schloss'], 'Schloss'));
        self::assertSame('q=Schloss', SearchMissFilter::query(['q' => 'Schloss'], ['not', 'a', 'string']));
    }

    public function test_invalid_utf8_is_not_logged(): void
    {
        self::assertTrue(SearchMissFilter::looksLikeContactData("Schloss \xC3"));
    }

    public function test_a_long_text_is_cut_to_100_characters_without_splitting_one(): void
    {
        $query = SearchMissFilter::query(['q' => str_repeat('ü', 150), 'guests' => '900']);
        self::assertNotNull($query);
        parse_str($query, $parsed);

        self::assertSame(str_repeat('ü', 100), $parsed['q']);
        self::assertSame('900', $parsed['guests']);
    }

    public function test_the_cut_drops_a_trailing_space_and_a_query_without_text_is_kept(): void
    {
        self::assertSame('q='.str_repeat('a', 99), SearchMissFilter::query(['q' => str_repeat('a', 99).' bbb']));
        self::assertSame('guests=900&event%5B0%5D=seminar', SearchMissFilter::query(['guests' => '900', 'event' => ['seminar']]));
        self::assertNull(SearchMissFilter::query([]));
    }
}
