<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\ValueObject;

use App\Shared\ValueObject\OgCard;
use PHPUnit\Framework\TestCase;

final class OgCardTest extends TestCase
{
    public function test_a_short_title_stays_on_one_line(): void
    {
        self::assertSame(['Forum Lindenhof'], OgCard::venue('Zürich', 'Forum Lindenhof', '20–120 Personen')->title);
    }

    public function test_a_title_wraps_at_word_boundaries_within_the_line_width(): void
    {
        $lines = OgCard::wrap('Seminarräume im Kanton Zürich', 22, 2);

        self::assertSame(['Seminarräume im Kanton', 'Zürich'], $lines);
    }

    public function test_a_title_beyond_its_lines_ends_in_an_ellipsis(): void
    {
        $lines = OgCard::venue('Reinach BL', 'Gasthof Felsenau am Hallwilersee mit Seeterrasse und Bootssteg', '')->title;

        self::assertCount(2, $lines);
        self::assertStringEndsWith('…', $lines[1]);
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(22, mb_strlen($line));
        }
    }

    public function test_the_ellipsis_follows_the_last_whole_word(): void
    {
        self::assertSame(['Gasthof Felsenau am', 'Hallwilersee mit…'], OgCard::wrap('Gasthof Felsenau am Hallwilersee mit Seeterrasse', 22, 2));
    }

    public function test_the_cut_never_splits_a_multibyte_letter(): void
    {
        foreach (['Ó', 'À', 'ü', '–'] as $letter) {
            $lines = OgCard::wrap('Schloss Wartensee am Bodensee '.$letter.' '.$letter.' '.str_repeat('X', 21).$letter.'XXXX', 22, 2);

            foreach ($lines as $line) {
                self::assertTrue(mb_check_encoding($line, 'UTF-8'), $letter);
            }
            self::assertStringEndsWith('…', $lines[1], $letter);
        }
    }

    public function test_an_unbroken_word_longer_than_a_line_is_cut(): void
    {
        $lines = OgCard::wrap(str_repeat('a', 50), 22, 2);

        self::assertSame([str_repeat('a', 22), str_repeat('a', 21).'…'], $lines);
    }

    public function test_the_eyebrow_and_the_facts_are_one_line_each(): void
    {
        $card = OgCard::landing('Eventlokale', 'Seminarräume', str_repeat('Seminar, ', 10));

        self::assertSame('landing', $card->variant);
        self::assertSame('Eventlokale', $card->eyebrow);
        self::assertLessThanOrEqual(44, mb_strlen($card->facts));
        self::assertStringEndsWith('…', $card->facts);
    }

    public function test_the_default_card_has_room_for_the_tagline_in_three_lines(): void
    {
        $card = OgCard::default('Eventlocations in der ganzen Schweiz, direkt bei der Location anfragen.');

        self::assertSame('default', $card->variant);
        self::assertCount(3, $card->title);
        self::assertStringNotContainsString('…', implode(' ', $card->title));
    }
}
