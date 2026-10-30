<?php

declare(strict_types=1);

namespace App\Seo\ValueObject;

/**
 * What the `<head>` of one page says about it. `canonical` is absolute; `latitude` and
 * `longitude` are set only with `ogType` `place`.
 */
final readonly class PageMeta
{
    public function __construct(
        public string $title,
        public string $description,
        public string $canonical,
        public bool $noindex,
        public string $ogType,
        public ?float $latitude = null,
        public ?float $longitude = null,
    ) {
    }
}
