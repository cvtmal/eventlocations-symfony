<?php

declare(strict_types=1);

namespace App\Seo\Twig;

use App\Seo\MarkdownText;
use Twig\Attribute\AsTwigFilter;

/**
 * The escaping of the Markdown templates (`seo/llms.txt.twig`, `seo/venue.md.twig`), which run
 * with autoescape off: `md` for one line, `md_block` for paragraphs, `md_url` for a link
 * destination. Every printed value goes through one of them.
 */
final class MarkdownExtension
{
    #[AsTwigFilter('md')]
    public function inline(string $text): string
    {
        return MarkdownText::inline($text);
    }

    #[AsTwigFilter('md_block')]
    public function block(string $text): string
    {
        return MarkdownText::block($text);
    }

    #[AsTwigFilter('md_url')]
    public function url(string $url): string
    {
        return MarkdownText::url($url);
    }
}
