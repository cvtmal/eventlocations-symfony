<?php

declare(strict_types=1);

namespace App\Tests\Support\Http;

use App\Venues\HttpCachePurger;

/**
 * The test environment's HttpCachePurger (config/services.yaml, when@test): records the purges.
 */
final class RecordingHttpCachePurger implements HttpCachePurger
{
    /** @var list<int> venue numbers, in call order */
    public array $venues = [];

    public int $listings = 0;

    /** Runs at each listings purge, for what must already hold by then. */
    public ?\Closure $onListings = null;

    public function purgeVenue(int $number): void
    {
        $this->venues[] = $number;
    }

    public function purgeListings(): void
    {
        ++$this->listings;
        if (null !== $this->onListings) {
            ($this->onListings)();
        }
    }
}
