<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

/**
 * One chip of a term group in the editor. `primaryPosition` (1–11, building only) marks the chips the public
 * filter shows before "Alle Location-Arten"; null for every other code. The label is `term.<group>.<code>` in
 * the `venues` catalogue.
 */
final readonly class TermOption
{
    public function __construct(
        public string $code,
        public ?int $primaryPosition,
    ) {
    }
}
