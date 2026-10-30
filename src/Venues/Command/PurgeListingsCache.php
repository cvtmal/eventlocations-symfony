<?php

declare(strict_types=1);

namespace App\Venues\Command;

/**
 * Purge the listing, landing pages and sitemap from the HTTP cache
 * (HttpCachePurger::purgeListings()). Dispatch it from a Venues handler with
 * DispatchAfterCurrentBusStamp, so it runs only after the change committed.
 */
final readonly class PurgeListingsCache
{
}
