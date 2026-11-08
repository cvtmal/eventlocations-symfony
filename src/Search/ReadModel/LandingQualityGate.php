<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Geo\ValueObject\Canton;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Whether a live landing page may be indexed (G12 `launch-regions`, D21). A page that fails stays reachable (200)
 * but is `noindex, follow` and is left out of the sitemap and llms.txt. Two rules, both data in
 * `config/services.yaml`:
 *
 * - at least `%landing.index_min_venues%` of its entries pass `Venues\VenueIndexGate` (facts checked, description
 *   written), so a page of unchecked or textless entries is thin however many it lists;
 * - a page in a canton (canton, town, event type in a canton) only in a launch region `%landing.launch_cantons%`
 *   (ZH, AG, ZG, BE at launch); the other cantons stay listed and reachable until they join the list. National event
 *   and building pages have no canton and follow the count alone.
 */
final readonly class LandingQualityGate
{
    /** @var list<Canton> */
    private array $launchCantons;

    /**
     * @param list<string> $launchCantons canton codes
     */
    public function __construct(
        #[Autowire('%landing.index_min_venues%')]
        private int $minVenues,
        #[Autowire('%landing.launch_cantons%')]
        array $launchCantons,
    ) {
        $this->launchCantons = array_map(Canton::from(...), $launchCantons);
    }

    /**
     * @param int $indexedVenues the page's entries that pass VenueIndexGate
     */
    public function passes(LandingPage $page, int $indexedVenues): bool
    {
        return $indexedVenues >= $this->minVenues
            && (null === $page->canton || \in_array($page->canton, $this->launchCantons, true));
    }
}
