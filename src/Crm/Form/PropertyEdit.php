<?php

declare(strict_types=1);

namespace App\Crm\Form;

/**
 * One inline edit as the island sends it: `{"field", "value", "expected"}` (D10). `value` and
 * `expected` are raw strings or null; `expected` is the value the page last saw (D11).
 */
final readonly class PropertyEdit
{
    public function __construct(
        public string $field,
        public ?string $value,
        public ?string $expected,
    ) {
    }
}
