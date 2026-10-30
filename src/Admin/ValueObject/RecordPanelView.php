<?php

declare(strict_types=1);

namespace App\Admin\ValueObject;

final readonly class RecordPanelView
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $template,
        public array $context,
    ) {
    }
}
