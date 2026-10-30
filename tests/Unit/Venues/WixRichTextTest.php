<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Tests\Support\Content\WixCsv;
use App\Venues\WixRichText;
use PHPUnit\Framework\TestCase;

final class WixRichTextTest extends TestCase
{
    public function test_paragraphs_come_out_in_order_and_empty_ones_are_dropped(): void
    {
        $json = WixCsv::richText(WixCsv::paragraph('Erster.'), WixCsv::emptyParagraph(), WixCsv::paragraph('   '), WixCsv::paragraph(' Zweiter. '));

        self::assertSame(['Erster.', 'Zweiter.'], WixRichText::paragraphs($json));
    }

    public function test_a_paragraph_of_only_a_no_break_space_is_empty(): void
    {
        $json = WixCsv::richText(WixCsv::paragraph("\u{00A0}Erster.\u{00A0}"), WixCsv::paragraph("\u{00A0}"), WixCsv::paragraph('Zweiter.'));

        self::assertSame(['Erster.', 'Zweiter.'], WixRichText::paragraphs($json));
    }

    public function test_list_items_form_one_paragraph_by_line(): void
    {
        $json = WixCsv::richText(WixCsv::paragraph('Davor.'), WixCsv::list('Eins', 'Zwei'), WixCsv::paragraph('Danach.'), WixCsv::list('Drei'));

        self::assertSame(['Davor.', "Eins\nZwei", 'Danach.', 'Drei'], WixRichText::paragraphs($json));
    }

    public function test_nested_lists_are_walked(): void
    {
        $json = WixCsv::richText(WixCsv::list('Eins', WixCsv::list('Innen'), 'Zwei'));

        self::assertSame(["Eins\nInnen\nZwei"], WixRichText::paragraphs($json));
    }

    public function test_an_image_is_skipped_with_its_caption(): void
    {
        $json = WixCsv::richText(WixCsv::paragraph('Text.'), WixCsv::image('Bildlegende'));

        self::assertSame(['Text.'], WixRichText::paragraphs($json));
    }

    public function test_text_comes_out_composed_and_without_zero_width_spaces(): void
    {
        $json = WixCsv::richText(WixCsv::paragraph("Scheu\u{200B}ne im Gru\u{0308}nen"));

        self::assertSame(['Scheune im Grünen'], WixRichText::paragraphs($json));
    }

    public function test_invalid_json_throws(): void
    {
        $this->expectException(\JsonException::class);

        WixRichText::paragraphs('{kein json');
    }
}
