<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seo;

use App\Seo\MarkdownText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function inlineTexts(): iterable
    {
        yield 'plain text stays' => ['Schloss am See', 'Schloss am See'];
        yield 'a link cannot form' => ['[Klick](https://evil.example)', '\[Klick\](https://evil.example)'];
        yield 'emphasis and code' => ['*fett* _kursiv_ `code`', '\*fett\* \_kursiv\_ \`code\`'];
        yield 'html' => ['<script>x</script>', '\<script\>x\</script\>'];
        yield 'a backslash' => ['a\b', 'a\\\\b'];
        yield 'a heading' => ['# Titel', '\# Titel'];
        yield 'a list item' => ['- Punkt', '\- Punkt'];
        yield 'a plus item' => ['+ Punkt', '\+ Punkt'];
        yield 'an ordered item' => ['1. Punkt', '1\. Punkt'];
        yield 'a decimal number' => ['6.2 km bis Bahnhof', '6.2 km bis Bahnhof'];
        yield 'a hyphenated word' => ['-frei und Apéro & Party', '-frei und Apéro & Party'];
        yield 'a thematic break' => ['---', '\---'];
        yield 'line breaks collapse' => ["Erste Zeile\n# Zweite", 'Erste Zeile # Zweite'];
        yield 'surrounding space goes' => ["  Garten \t", 'Garten'];
    }

    #[DataProvider('inlineTexts')]
    public function test_inline_escapes_markdown_syntax(string $text, string $expected): void
    {
        self::assertSame($expected, MarkdownText::inline($text));
    }

    public function test_block_keeps_paragraphs_and_lines(): void
    {
        self::assertSame(
            "Erster Absatz\nzweite Zeile\n\n\\# Kein Titel\n\\- keine Liste",
            MarkdownText::block("  Erster Absatz  \n  zweite Zeile\n\n\n\n# Kein Titel\n- keine Liste\n"),
        );
    }

    public function test_block_of_only_whitespace_is_empty(): void
    {
        self::assertSame('', MarkdownText::block(" \n\n \t\n"));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function urls(): iterable
    {
        yield 'plain stays bare' => ['https://schloss.example.ch/hochzeit?x=1', 'https://schloss.example.ch/hochzeit?x=1'];
        yield 'parentheses' => ['https://example.ch/a_(b)', '<https://example.ch/a_(b)>'];
        yield 'space and brackets' => [' https://example.ch/a b<c> ', '<https://example.ch/a%20b%3Cc%3E>'];
    }

    #[DataProvider('urls')]
    public function test_url_is_a_safe_link_destination(string $url, string $expected): void
    {
        self::assertSame($expected, MarkdownText::url($url));
    }

    public function test_tidy_collapses_blank_runs_and_ends_in_one_newline(): void
    {
        self::assertSame("# A\n\n- b\n\nc\n", MarkdownText::tidy("\n\n# A   \n\n\n\n- b\n\n\nc\n\n\n"));
    }
}
