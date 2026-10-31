<?php

declare(strict_types=1);

namespace App\Shared\Extension;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A named Markdown text block staff edit in the back office (#26) and a page renders with
 * `{{ content_block('key') }}`. Implement it in a module's `Extension` layer; autoconfiguration
 * registers it with ContentBlockRegistry. Shared holds the contract so a public module can
 * declare a block without depending on Cms.
 */
#[AutoconfigureTag('content_block.definition')]
interface ContentBlockDefinition
{
    /**
     * Dotted and lower-case (`listing.intro`), as chk_content_blocks_key allows. Never starts
     * with `landing.`, which landing pages use.
     */
    public function key(): string;

    public function label(): TranslatableInterface;

    /**
     * Where the block appears on the public site, shown beside the editor.
     */
    public function placement(): TranslatableInterface;

    /**
     * Characters (code points, trimmed) one locale's body may have.
     */
    public function maxLength(): int;

    /**
     * Shown on the page, and named in the editor, when nothing is set; null when the page then
     * renders nothing. A caller's own fallback wins over it.
     */
    public function fallback(): ?TranslatableInterface;
}
