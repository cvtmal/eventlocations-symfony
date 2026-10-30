<?php

declare(strict_types=1);

namespace App\Admin\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;

final readonly class NavigationItemView
{
    /**
     * @param array<string, scalar> $routeParameters
     */
    public function __construct(
        public TranslatableInterface $label,
        public string $icon,
        public string $route,
        public array $routeParameters,
        public ?int $badge,
        public bool $active,
    ) {
    }
}
