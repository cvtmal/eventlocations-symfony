<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Mailer;

use App\Shared\Mailer\EmailHtmlToTextConverter;
use PHPUnit\Framework\TestCase;

final class EmailHtmlToTextConverterTest extends TestCase
{
    public function test_drops_head_style_and_preheader(): void
    {
        $text = $this->convert(
            '<!DOCTYPE html><html><head><title>Subject</title><style>.hl-p{color:red}</style></head><body>'
            .'<div class="hl-preheader" style="display:none;">Preview text&#8199;&#65279;&#847;</div>'
            .'<table><tr><td class="hl-p">Body</td></tr></table></body></html>',
        );

        self::assertSame('Body', $text);
    }

    public function test_drops_aria_hidden_rows(): void
    {
        $text = $this->convert($this->card(
            '<tr><td><table><tr><td aria-hidden="true">&#9835;&#xFE0E;</td><td><a href="http://localhost/">Hochzeitslocation</a></td></tr></table></td></tr>'
            .'<tr><td class="hl-p">Body</td></tr>'
            .'<tr><td aria-hidden="true"><table><tr><td width="75%">&nbsp;</td><td>meter</td></tr></table></td></tr>',
        ));

        self::assertSame("Hochzeitslocation\n\nBody", $text);
    }

    public function test_button_becomes_label_and_url(): void
    {
        $text = $this->convert($this->card(
            '<tr><td><table><tr><td><a href="http://localhost/hochzeitslocation/seeblick" class="hl-btn"> Zur Location </a></td></tr></table></td></tr>',
        ));

        self::assertSame('Zur Location: http://localhost/hochzeitslocation/seeblick', $text);
    }

    public function test_fallback_link_keeps_its_visible_url_once(): void
    {
        $text = $this->convert($this->card(
            '<tr><td class="hl-muted">Copy this link:<br><a href="http://localhost/verify" class="hl-link">http://localhost/verify</a></td></tr>',
        ));

        self::assertSame(1, substr_count($text, 'http://localhost/verify'));
    }

    public function test_rows_are_separated_by_a_blank_line(): void
    {
        $text = $this->convert($this->card(
            "<tr><td>\n    <h1>Guten Tag</h1>\n</td></tr>\n<tr><td class=\"hl-p\">First</td></tr>\n<tr><td class=\"hl-p\">Second</td></tr>",
        ));

        self::assertSame("Guten Tag\n\nFirst\n\nSecond", $text);
    }

    public function test_br_becomes_a_line_break(): void
    {
        $text = $this->convert($this->card('<tr><td>One<br>Two</td></tr>'));

        self::assertSame("One\nTwo", $text);
    }

    public function test_entities_are_decoded(): void
    {
        $text = $this->convert($this->card(
            '<tr><td>Hochzeitslocation &middot; hochzeitslocation.ch</td></tr>'
            .'<tr><td>You&#039;re in</td></tr>'
            .'<tr><td><a href="http://localhost/verify-email?expires=1&amp;id=a" class="hl-btn">Verify</a></td></tr>',
        ));

        self::assertSame("Hochzeitslocation · hochzeitslocation.ch\n\nYou're in\n\nVerify: http://localhost/verify-email?expires=1&id=a", $text);
    }

    public function test_empty_spacer_rows_are_skipped(): void
    {
        $text = $this->convert($this->card(
            '<tr><td class="hl-p">Before</td></tr>'
            .'<tr><td><table><tr><td class="hl-rule" height="1">&nbsp;</td></tr></table></td></tr>'
            .'<tr><td>   </td></tr>'
            .'<tr><td class="hl-p">After</td></tr>',
        ));

        self::assertSame("Before\n\nAfter", $text);
    }

    public function test_a_cell_holding_only_an_image_yields_no_block(): void
    {
        $text = $this->convert($this->card(
            '<tr><td class="hl-band"><a href="http://localhost/"><img src="cid:logo" alt="deine-eventlocation.ch" width="215" height="24"></a></td></tr>'
            .'<tr><td><img src="cid:logo" alt="deine-eventlocation.ch"></td></tr>'
            .'<tr><td class="hl-p">Body</td></tr>',
        ));

        self::assertSame('Body', $text);
    }

    public function test_html_without_tables_falls_back_to_body_text(): void
    {
        $text = $this->convert("<html><body><p>Hello\u{00A0}  there</p>\n\n<p>Bye</p></body></html>");

        self::assertSame("Hello there\nBye", $text);
    }

    private function card(string $rows): string
    {
        return '<!DOCTYPE html><html><body><table><tr><td><table>'.$rows.'</table></td></tr></table></body></html>';
    }

    private function convert(string $html): string
    {
        return (new EmailHtmlToTextConverter())->convert($html, 'utf-8');
    }
}
