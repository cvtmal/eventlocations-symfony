<?php

declare(strict_types=1);

namespace App\Venues;

/**
 * Removes venue pages from the HTTP cache in front of the app (S10, CMS-10). The contract
 * for #19 (the implementation) and for the write side (#9's import, #24–#26's CMS):
 *
 * - purgeVenue(): every public URL of that venue: its detail page under the current and
 *   every history slug, per enabled locale, and the canton/landing pages it appears on.
 * - purgeListings(): the listing, the landing pages and the sitemap.
 *
 * Callers never call it directly: a Venues handler dispatches PurgeVenueCache or
 * PurgeListingsCache with DispatchAfterCurrentBusStamp, so a purge runs only after the
 * change committed, and not at all when it failed.
 *
 * An implementation never throws (it logs and returns): purges run after the commit, and
 * an exception would report a committed change as failed.
 *
 * The default is NullHttpCachePurger (there is no cache yet); #19 replaces the alias in
 * config/services.yaml with its proxy's purge.
 */
interface HttpCachePurger
{
    public function purgeVenue(int $number): void;

    public function purgeListings(): void;
}
