<?php

declare(strict_types=1);

namespace App\Venues\SiteImport;

use App\Shared\Http\SafeUrl;

/**
 * A link on a page to another page of the same site that is probably worth reading (rooms, events, gallery, contact).
 */
final readonly class PageLink
{
    public function __construct(
        public SafeUrl $url,
        public string $text,
        public int $score,
    ) {
    }
}
