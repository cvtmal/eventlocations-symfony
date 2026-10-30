<?php

declare(strict_types=1);

namespace App\Shared\Translation;

/**
 * One `<link rel="alternate" hreflang="…" href="…">` entry (SEO-6).
 */
final readonly class HreflangAlternate
{
    public function __construct(
        public string $hreflang,
        public string $href,
    ) {
    }
}
