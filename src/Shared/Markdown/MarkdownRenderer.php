<?php

declare(strict_types=1);

namespace App\Shared\Markdown;

use League\CommonMark\CommonMarkConverter;

/**
 * Markdown to HTML for text staff write, here so the back office's preview and #26's public pages render alike.
 *
 * The options are security settings, not preferences: league/commonmark is unsafe by default. Raw HTML is
 * escaped, `javascript:`, `vbscript:`, `file:` and `data:` links are dropped, and the two limits stop hostile
 * nesting and delimiter runs from costing quadratic time. Only the core extension is enabled.
 */
final class MarkdownRenderer
{
    private ?CommonMarkConverter $converter = null;

    public function render(string $markdown): string
    {
        $this->converter ??= new CommonMarkConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'max_delimiters_per_line' => 1000,
        ]);

        return (string) $this->converter->convert($markdown);
    }
}
