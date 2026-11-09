<?php

declare(strict_types=1);

namespace App\Search\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Attribute\AsTwigFunction;

/**
 * `nearby_map_enabled()`: whether the near-me page (#52) shows Google's map, which it does only with a
 * `GOOGLE_MAPS_JS_KEY` (`NearbyController`). /datenschutz explains the map's load on view only then (f16, N2).
 */
final class NearbyMapExtension
{
    public function __construct(
        #[Autowire('%env(default::GOOGLE_MAPS_JS_KEY)%')]
        private readonly ?string $mapsKey,
    ) {
    }

    #[AsTwigFunction('nearby_map_enabled')]
    public function enabled(): bool
    {
        return '' !== (string) $this->mapsKey;
    }
}
