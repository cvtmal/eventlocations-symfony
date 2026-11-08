<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Whether a live landing page may be indexed (F8; the hook G12 `launch-regions` fills, D21). A page
 * that fails stays reachable (200) but is `noindex, follow` and is left out of the sitemap and
 * llms.txt. Until G12 adds its own rules (facts checked, text written) the gate is the venue count
 * `%landing.index_min_venues%`, which equals `%landing.min_venues%`: every live page passes.
 */
final readonly class LandingQualityGate
{
    public function __construct(
        #[Autowire('%landing.index_min_venues%')]
        private int $minVenues,
    ) {
    }

    public function passes(LandingPage $page): bool
    {
        return $page->venues >= $this->minVenues;
    }
}
