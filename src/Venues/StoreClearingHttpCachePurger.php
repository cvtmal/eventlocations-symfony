<?php

declare(strict_types=1);

namespace App\Venues;

use App\Shared\Http\HttpCacheClearer;

/**
 * Both purges invalidate every entry of the HttpCache store (S10, #19), metadata only, so a
 * request already reading a body is not broken (HttpCacheClearer::invalidate()). A venue
 * appears on more pages than a URL list could name: its detail page under every current and
 * history slug, the listing pages of every filter state that matches it, its canton, town and
 * building landing pages, the similar-venues fragment of other venues' pages, the sitemaps and
 * the footer's canton list. Symfony's store has no tags, so a precise purge would miss some;
 * invalidating everything never does, and edits are rare enough that a cold store costs little.
 *
 * Despite the name, a purge only invalidates (md/); HttpCacheClearer::clear() is what empties the
 * store, and only the console command calls it.
 */
final readonly class StoreClearingHttpCachePurger implements HttpCachePurger
{
    public function __construct(
        private HttpCacheClearer $clearer,
    ) {
    }

    public function purgeVenue(int $number): void
    {
        $this->clearer->invalidate();
    }

    public function purgeListings(): void
    {
        $this->clearer->invalidate();
    }
}
