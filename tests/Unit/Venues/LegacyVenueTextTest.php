<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\ContentFormat;
use App\Venues\LegacyVenueText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyVenueTextTest extends TestCase
{
    public function test_fold_ignores_case_whitespace_and_a_trailing_colon(): void
    {
        self::assertSame('kapazität für stehempfang / apéro', LegacyVenueText::fold("  Kapazität  für\u{00A0}Stehempfang / Apéro : "));
        self::assertSame('beschrieb', LegacyVenueText::fold('Beschrieb.'));
    }

    public function test_rule_1_takes_the_section_after_the_label(): void
    {
        $result = LegacyVenueText::description(['Einleitung', 'Beschrieb:', 'Erster Absatz.', 'Zweiter Absatz.', 'Kapazität:', '120 Gäste']);

        self::assertSame(['text' => "Erster Absatz.\n\nZweiter Absatz.", 'rule' => 1], $result);
    }

    public function test_a_decomposed_label_still_matches(): void
    {
        $result = LegacyVenueText::description(['Beschrieb', 'Absatz.', "Kapazita\u{0308}t", '120 Gäste']);

        self::assertSame(['text' => 'Absatz.', 'rule' => 1], $result);
    }

    public function test_rule_2_takes_the_text_before_the_first_fact(): void
    {
        $result = LegacyVenueText::description(['Eine Einleitung.', 'Noch ein Absatz.', 'Nutzung', 'Exklusiv']);

        self::assertSame(['text' => "Eine Einleitung.\n\nNoch ein Absatz.", 'rule' => 2], $result);
    }

    public function test_rule_3_takes_long_paragraphs(): void
    {
        $long = str_repeat('Lang. ', 40);

        $result = LegacyVenueText::description(['Einrichtung', 'Kurz', $long, 'Service', 'Kurz auch']);

        self::assertSame(['text' => $long, 'rule' => 3], $result);
    }

    public function test_rule_4_gives_no_description(): void
    {
        self::assertSame(['text' => null, 'rule' => 4], LegacyVenueText::description(['Einrichtung', 'Tische', 'Service', 'Ja']));
        self::assertSame(['text' => null, 'rule' => 4], LegacyVenueText::description([]));
    }

    public function test_a_short_text_is_the_summary_with_collapsed_whitespace(): void
    {
        self::assertSame(['text' => 'Ein Absatz. Noch einer.', 'source' => 'whole'], LegacyVenueText::summary("Ein Absatz.\n\nNoch  einer.", 'Ländlich'));
    }

    public function test_exactly_200_characters_are_whole(): void
    {
        $text = str_repeat('a', 200);

        self::assertSame(['text' => $text, 'source' => 'whole'], LegacyVenueText::summary($text, ''));
    }

    public function test_the_last_sentence_that_fits_ends_the_summary(): void
    {
        $first = 'Der erfundene Hof liegt an einem erfundenen See und bietet viel Platz für ein Fest.';
        $second = 'Die Scheune fasst mehr Gäste, als die Gemeinde Einwohner hat, und der Garten ist noch grösser.';
        $third = str_repeat('Dann kommt noch sehr viel mehr Text ', 4);

        $summary = LegacyVenueText::summary($first.' '.$second.' '.$third, '');

        self::assertSame(['text' => $first.' '.$second, 'source' => 'sentence'], $summary);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function falseEnds(): iterable
    {
        yield 'ordinal' => ['Das Schloss wurde im 16. Jahrhundert gebaut und steht bis heute auf dem erfundenen Hügel'];
        yield 'St.' => ['Der Hof steht unweit von St. Gallen und ist von allen Seiten her gut zu erreichen'];
        yield 'max.' => ['Im grossen Saal finden (max. 120 Gäste an langen Tischen Platz und werden gut bewirtet'];
        yield 'bzw.' => ['Die Feier beginnt im Garten bzw. Die Scheune nimmt alle auf, wenn das Wetter nicht mitspielt'];
    }

    #[DataProvider('falseEnds')]
    public function test_abbreviations_and_ordinals_do_not_end_a_sentence(string $sentence): void
    {
        $text = $sentence.' '.str_repeat('und danach kommt noch sehr viel mehr Text ohne jedes Satzende ', 5);

        $summary = LegacyVenueText::summary($text, '');

        self::assertSame('word', $summary['source'], $summary['text']);
    }

    public function test_a_sentence_end_below_60_characters_gives_a_word_cut(): void
    {
        $text = 'Kurzer Satz. '.str_repeat('und danach kommt noch sehr viel mehr Text ohne jedes Satzende ', 5);

        $summary = LegacyVenueText::summary($text, '');

        self::assertSame('word', $summary['source']);
        self::assertStringEndsWith('…', $summary['text']);
        self::assertLessThanOrEqual(200, mb_strlen($summary['text']));
        self::assertStringNotContainsString(' …', $summary['text']);
    }

    public function test_a_text_without_spaces_is_cut_hard(): void
    {
        $summary = LegacyVenueText::summary(str_repeat('ä', 300), '');

        self::assertSame(str_repeat('ä', 199).'…', $summary['text']);
    }

    public function test_without_a_description_the_ambience_is_the_summary(): void
    {
        self::assertSame(['text' => 'Ländlich', 'source' => 'ambience'], LegacyVenueText::summary(null, ' Ländlich '));
    }

    public function test_the_key_is_the_name_slug_and_the_id(): void
    {
        self::assertSame('landgasthof-baerenhof-1a2b3c4d', LegacyVenueText::key('Landgasthof Bärenhof', '1a2b3c4d'));
    }

    public function test_a_long_key_is_cut_to_the_format_limit(): void
    {
        $key = LegacyVenueText::key(trim(str_repeat('Langer Name ', 20)), '1a2b3c4d');

        self::assertTrue(ContentFormat::isKey($key), $key);
        self::assertStringEndsWith('-1a2b3c4d', $key);
    }
}
