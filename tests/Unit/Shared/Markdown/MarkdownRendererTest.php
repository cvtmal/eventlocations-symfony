<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Markdown;

use App\Shared\Markdown\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;

final class MarkdownRendererTest extends TestCase
{
    public function test_a_script_comes_out_as_text(): void
    {
        $html = (new MarkdownRenderer())->render('<script>alert(1)</script>');

        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_an_event_handler_comes_out_as_text(): void
    {
        $html = (new MarkdownRenderer())->render('<img src=x onerror=alert(1)>');

        self::assertStringNotContainsString('<img', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeLinks(): iterable
    {
        yield 'inline javascript' => ['[x](javascript:alert(1))'];
        yield 'autolink javascript' => ['<javascript:alert(1)>'];
        yield 'mixed case' => ['[x](JaVaScRiPt:alert(1))'];
        yield 'vbscript' => ['[x](vbscript:msgbox(1))'];
        yield 'data' => ['[x](data:text/html,<script>alert(1)</script>)'];
    }

    #[DataProvider('unsafeLinks')]
    public function test_an_unsafe_link_loses_its_url(string $markdown): void
    {
        $html = (new MarkdownRenderer())->render($markdown);

        self::assertDoesNotMatchRegularExpression('/href="(?!")/', $html);
        self::assertDoesNotMatchRegularExpression('/href="\s*(javascript|vbscript|data):/i', $html);
    }

    public function test_a_link_title_cannot_add_an_attribute(): void
    {
        $html = (new MarkdownRenderer())->render('[x](https://example.ch "a\" onmouseover=\"alert(1)")');

        $link = (new Crawler('<!doctype html><html><body>'.$html.'</body></html>'))->filter('a');

        self::assertNull($link->attr('onmouseover'));
        self::assertSame('https://example.ch', $link->attr('href'));
    }

    public function test_hostile_nesting_and_delimiter_runs_finish(): void
    {
        $renderer = new MarkdownRenderer();

        self::assertNotSame('', $renderer->render(str_repeat('> ', 10000)));
        self::assertNotSame('', $renderer->render(str_repeat('*a', 10000)));
    }

    public function test_ordinary_markdown_renders(): void
    {
        $html = (new MarkdownRenderer())->render("**fett** und *kursiv*\n\n- eins\n- zwei\n\n[Link](https://example.ch)");

        self::assertStringContainsString('<strong>fett</strong>', $html);
        self::assertStringContainsString('<em>kursiv</em>', $html);
        self::assertStringContainsString('<li>eins</li>', $html);
        self::assertStringContainsString('<a href="https://example.ch">Link</a>', $html);
    }
}
