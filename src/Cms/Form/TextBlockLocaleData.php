<?php

declare(strict_types=1);

namespace App\Cms\Form;

/**
 * A text block's Markdown in one locale (#26). The limit is the block's own (`ContentBlockDefinition::
 * maxLength()`), so `TextBlockLocaleType` sets it; a blank body removes the row.
 */
final class TextBlockLocaleData
{
    public ?string $body = null;
}
