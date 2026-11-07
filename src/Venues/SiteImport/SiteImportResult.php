<?php

declare(strict_types=1);

namespace App\Venues\SiteImport;

use App\Venues\ValueObject\ImageCandidate;

/**
 * What one crawl of a venue's website found. Nothing here is stored yet: the console command prints it and hands it
 * to RecordSiteImport.
 *
 * @phpstan-import-type Observation from FactSet
 */
final readonly class SiteImportResult
{
    /**
     * @param array<string, list<Observation>>                         $facts
     * @param list<ImageCandidate>                                     $candidates
     * @param list<array{url: string, status: int, bytes: int}>        $pages      the pages that were read, the entry page first
     * @param list<array{url: string, reason: string, detail: string}> $refused    the URLs that were not fetched, and why
     * @param list<string>                                             $redirects  the URLs the entry page was redirected through
     */
    public function __construct(
        public string $url,
        public string $finalUrl,
        public array $facts,
        public array $candidates,
        public int $skippedImages,
        public array $pages,
        public array $refused,
        public array $redirects,
        public int $bytes,
        public float $seconds,
        public \DateTimeImmutable $fetchedAt,
    ) {
    }

    /**
     * The part of the result a source record keeps besides facts and candidates.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return [
            'final_url' => $this->finalUrl,
            'redirects' => $this->redirects,
            'pages' => $this->pages,
            'refused' => $this->refused,
            'skipped_images' => $this->skippedImages,
            'bytes' => $this->bytes,
            'seconds' => round($this->seconds, 1),
        ];
    }
}
