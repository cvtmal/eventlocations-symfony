<?php

declare(strict_types=1);

namespace App\Shared\Command;

/**
 * G12: a search that found nothing, for the weekly list on the staff scorecard. `query` is the canonical,
 * URL-encoded query of the search (`http_build_query(SearchCriteria::toQuery())`, page dropped): the text and the
 * filters, nothing of the person. Dispatched by Search's listing, handled best effort by Stats
 * (`RecordSearchMissHandler`), which never throws.
 */
final readonly class RecordSearchMiss
{
    public function __construct(
        public string $query,
    ) {
    }
}
