<?php

declare(strict_types=1);

namespace App\Shared\Command;

/**
 * G12: a search that found nothing, for the weekly list on the staff scorecard. `query` is the canonical,
 * URL-encoded query of the search (`Search\SearchMissFilter::query(SearchCriteria::toQuery())`, page 1 only): the
 * text, cut to 100 characters, and the filters, nothing of the person; a text that looks like contact data is never
 * dispatched (D45, D46). Dispatched by Search's listing, handled best effort by Stats (`RecordSearchMissHandler`),
 * which never throws; `PurgeSearchMisses` deletes a week after 12 months (D44).
 */
final readonly class RecordSearchMiss
{
    public function __construct(
        public string $query,
    ) {
    }
}
