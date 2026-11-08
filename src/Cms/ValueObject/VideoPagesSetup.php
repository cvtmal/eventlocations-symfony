<?php

declare(strict_types=1);

namespace App\Cms\ValueObject;

use App\Cms\ReadModel\VenueHeader;

/**
 * What a host of `Cms\VenueVideoPages` brings (G15): staff's Videos tab and the manager's. `$route` is the list route;
 * the others are named `<route>_up`, `_down` and `_remove`, each with `number` and `id`; `$csrfId` is the token id of
 * the move and remove forms. The template receives the service's context plus `$context($venue)` (the manager's
 * layout needs its submission, plan and offline form).
 */
final readonly class VideoPagesSetup
{
    /**
     * @param \Closure(VenueHeader): array<string, mixed> $context
     */
    public function __construct(
        public string $route,
        public string $csrfId,
        public string $template,
        public \Closure $context,
    ) {
    }
}
