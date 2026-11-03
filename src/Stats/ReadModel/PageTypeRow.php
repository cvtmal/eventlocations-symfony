<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

use App\Stats\ValueObject\PageType;

/**
 * Page views of one page type over a period (#47). `visitors` adds up each day's distinct visitors.
 */
final readonly class PageTypeRow
{
    public function __construct(
        public PageType $pageType,
        public int $pageViews,
        public int $visitors,
    ) {
    }
}
