<?php

declare(strict_types=1);

namespace App\Admin\ValueObject;

use App\Admin\Extension\NavigationSection;

final readonly class NavigationSectionView
{
    /**
     * @param list<NavigationItemView> $items
     */
    public function __construct(
        public NavigationSection $section,
        public array $items,
    ) {
    }
}
