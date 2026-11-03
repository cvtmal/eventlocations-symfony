<?php

declare(strict_types=1);

namespace App\Crm;

/**
 * The counts of one EventlokaleCrawl run (#49).
 */
final readonly class EventlokaleCrawlResult
{
    public function __construct(
        /** The venue count the API reported. */
        public int $total,
        /** Unique ids in the cached list. */
        public int $listed,
        public int $fetched,
        /** Details already cached, so not requested. */
        public int $skipped,
        public int $gone,
        public int $failed,
        /** Stopped after EventlokaleCrawl::MAX_CONSECUTIVE_429 rate-limit answers in a row. */
        public bool $rateLimited,
    ) {
    }
}
