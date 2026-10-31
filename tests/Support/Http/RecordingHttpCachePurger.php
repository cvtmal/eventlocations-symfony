<?php

declare(strict_types=1);

namespace App\Tests\Support\Http;

use App\Venues\HttpCachePurger;
use App\Venues\StoreClearingHttpCachePurger;

/**
 * The test environment's HttpCachePurger (config/services.yaml, when@test): records the purges,
 * then delegates to the real purger, so a test sees the store invalidated as in prod (#19).
 * Order in purgeListings(): record, run onListings (#26), then delegate.
 */
final class RecordingHttpCachePurger implements HttpCachePurger
{
    /** @var list<int> venue numbers, in call order */
    public array $venues = [];

    public int $listings = 0;

    /** Runs at each listings purge, for what must already hold by then. */
    public ?\Closure $onListings = null;

    public function __construct(
        private readonly ?StoreClearingHttpCachePurger $inner = null,
    ) {
    }

    public function purgeVenue(int $number): void
    {
        $this->venues[] = $number;
        $this->inner?->purgeVenue($number);
    }

    public function purgeListings(): void
    {
        ++$this->listings;
        if (null !== $this->onListings) {
            ($this->onListings)();
        }
        $this->inner?->purgeListings();
    }
}
