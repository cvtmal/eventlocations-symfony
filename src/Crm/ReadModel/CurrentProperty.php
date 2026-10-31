<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * The stored value of one inline-edited field in its canonical form, the field's version (D11):
 * ids for references, codes for enums, null for an empty field.
 */
final readonly class CurrentProperty
{
    public function __construct(
        public ?string $value,
    ) {
    }
}
