<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * An option the deal's selects offer: `id` is the value, a venue's id or a tier's code (F12).
 */
final readonly class DealFormOption
{
    public function __construct(
        public string $id,
        public string $label,
    ) {
    }
}
