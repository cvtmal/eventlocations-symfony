<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * The hard caps of one crawl (one FetchSession). A page that needs more than this is not a venue's website, so the
 * numbers are small on purpose and nothing raises them at run time.
 */
final readonly class FetchLimits
{
    public function __construct(
        /** Bytes of one response body, after decompression. */
        public int $maxBytesPerResponse = 1_500_000,
        /** Bytes of all bodies of a crawl. */
        public int $maxTotalBytes = 6_000_000,
        public int $maxRedirects = 3,
        /** Pages (the entry page included, robots.txt excluded) per crawl. */
        public int $maxPages = 8,
        /** Seconds without a byte before a request is given up. */
        public float $idleTimeoutSeconds = 8.0,
        /** Seconds one request may take from start to the last byte. */
        public float $requestSeconds = 15.0,
        /** Seconds of the whole crawl, the waits between requests included. */
        public float $totalSeconds = 90.0,
        /** Seconds between two requests to one host. */
        public float $minDelaySeconds = 1.0,
        /** A robots.txt Crawl-delay above this refuses the host instead of waiting. */
        public float $maxCrawlDelaySeconds = 10.0,
        public int $maxRobotsBytes = 512_000,
    ) {
    }
}
