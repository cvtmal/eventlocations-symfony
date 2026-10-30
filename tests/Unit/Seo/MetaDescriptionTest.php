<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seo;

use App\Seo\MetaDescription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetaDescriptionTest extends TestCase
{
    public function test_a_short_text_is_unchanged(): void
    {
        self::assertSame('Ein Schloss am See.', MetaDescription::fit('Ein Schloss am See.'));
    }

    public function test_an_empty_text_stays_empty(): void
    {
        self::assertSame('', MetaDescription::fit("  \n "));
    }

    public function test_whitespace_collapses(): void
    {
        self::assertSame('Erster Absatz. Zweiter Absatz.', MetaDescription::fit("  Erster Absatz.\n\n  Zweiter\tAbsatz. "));
    }

    public function test_it_stops_at_the_last_sentence_that_fits(): void
    {
        $first = str_repeat('Wort ', 16).'Ende.';
        $second = str_repeat('Noch ein Wort ', 10).'Schluss.';

        self::assertSame($first, MetaDescription::fit($first.' '.$second));
    }

    public function test_a_sentence_ending_exactly_at_the_limit_is_kept(): void
    {
        $sentence = str_repeat('a', 154).'.';

        self::assertSame($sentence, MetaDescription::fit($sentence.' Danach kommt mehr.'));
    }

    public function test_one_long_sentence_is_cut_at_a_word(): void
    {
        $fitted = MetaDescription::fit(str_repeat('Wort ', 60).'Ende.');

        self::assertLessThanOrEqual(MetaDescription::MAX, mb_strlen($fitted));
        self::assertStringEndsWith('Wort…', $fitted);
    }

    public function test_an_early_abbreviation_does_not_win(): void
    {
        $fitted = MetaDescription::fit('Platz für ca. '.str_repeat('hundert Gäste ', 20).'am See.');

        self::assertGreaterThan(100, mb_strlen($fitted));
        self::assertStringEndsWith('…', $fitted);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function abbreviationsLate(): iterable
    {
        yield 'bei St.' => ['Das alte Landgut hat einen grossen Garten mit Baumbestand, eine weite Wiese für die freie Trauung und liegt ruhig bei St. Gallen, nur zwanzig Minuten vom Bahnhof entfernt, mit Parkplätzen am Haus.'];
        yield 'für ca.' => ['Der helle Saal im ersten Stock hat grosse Fenster zum See, eine eigene Bar und bietet Platz für ca. 120 Gäste an runden Tischen, dazu eine Terrasse für den Apéro am Abend.'];
    }

    #[DataProvider('abbreviationsLate')]
    public function test_a_late_abbreviation_is_no_sentence_end(string $text): void
    {
        $fitted = MetaDescription::fit($text);

        self::assertLessThanOrEqual(MetaDescription::MAX, mb_strlen($fitted));
        self::assertStringEndsWith('…', $fitted);
        self::assertMatchesRegularExpression('/ (St\. Gallen|ca\. 120)/u', $fitted);
    }

    public function test_an_abbreviation_falls_back_to_the_sentence_before(): void
    {
        $first = 'Ein altes Landgut mit Garten und weitem Blick auf die Berge und den See, ideal für Hochzeiten.';

        self::assertSame($first, MetaDescription::fit($first.' Der Saal bietet Platz für ca. 120 Gäste, die Terrasse für den Apéro und die Scheune für die Party.'));
    }

    public function test_umlauts_count_as_one_character(): void
    {
        $text = str_repeat('ü', 155);

        self::assertSame($text, MetaDescription::fit($text));
    }

    public function test_one_endless_word_still_fits(): void
    {
        $fitted = MetaDescription::fit(str_repeat('ü', 156));

        self::assertSame(MetaDescription::MAX, mb_strlen($fitted));
        self::assertStringEndsWith('ü…', $fitted);
    }
}
