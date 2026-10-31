<?php

declare(strict_types=1);

namespace App\Cms\ValueObject;

/**
 * A text block as the back office names it (#26, P11): its content_blocks key (`listing.intro`,
 * `footer.note`), the shape `chk_content_blocks_key` allows. Only a declared block is editable; a landing
 * key matches the shape and is a 404 because no definition has it.
 */
final readonly class TextBlockKey
{
    public const string REQUIREMENT = '[a-z][a-z0-9_]*(?:\.[a-z0-9_-]+)+';
}
