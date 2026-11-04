<?php

declare(strict_types=1);

namespace App\Shared\Extension;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A text block whose page shows paragraphs of its own while the field is empty, which `fallback()` cannot say,
 * because a declared fallback is one paragraph (the home text, #36). The page and the block editor read the same
 * list, so the editor's hint names exactly what the page shows.
 */
interface ContentBlockPageDefault
{
    /**
     * @return non-empty-list<TranslatableInterface>
     */
    public function pageDefault(): array;
}
