<?php

declare(strict_types=1);

namespace App\Admin\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;

final readonly class RecordTabView
{
    /**
     * @param array<string, scalar> $routeParameters
     */
    public function __construct(
        public TranslatableInterface $label,
        public string $route,
        public array $routeParameters,
    ) {
    }
}
