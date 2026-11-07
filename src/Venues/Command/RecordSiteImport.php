<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Venues\ValueObject\ImageCandidate;
use App\Venues\ValueObject\SourceType;

/**
 * Store the result of one website crawl (G3) as a source record with its photo candidates. The record replaces
 * the current one of the same URL as the venue's latest; the old one stays as `superseded`.
 */
final readonly class RecordSiteImport
{
    /**
     * @param array<string, list<array{value: mixed, confidence: float, source_url: string, via: string}>> $facts
     * @param array<string, mixed>                                                                         $report
     * @param list<ImageCandidate>                                                                         $candidates
     */
    public function __construct(
        public string $url,
        public ?string $venueId,
        public SourceType $sourceType,
        public array $facts,
        public array $report,
        public array $candidates,
        public \DateTimeImmutable $fetchedAt,
    ) {
    }
}
