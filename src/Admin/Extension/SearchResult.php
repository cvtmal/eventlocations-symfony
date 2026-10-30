<?php

declare(strict_types=1);

namespace App\Admin\Extension;

final readonly class SearchResult
{
    public function __construct(
        public string $type,
        public string $label,
        public ?string $detail,
        public string $url,
    ) {
    }
}
