<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * What app:media:regenerate did.
 */
final readonly class RegenerateMediaResult
{
    /**
     * @param list<string> $failures     "<venue-number>/<photo-or-document-id>: <message>", one per item skipped
     * @param bool         $venueMissing the requested venue number does not exist
     */
    public function __construct(
        public int $venues,
        public int $photos,
        public int $documents,
        public array $failures,
        public bool $venueMissing,
    ) {
    }
}
