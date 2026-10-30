<?php

declare(strict_types=1);

namespace App\Venues\Command;

/**
 * Record one finished app:venues:import run (not a dry run) as a content_imports row.
 */
final readonly class RecordContentImport
{
    /**
     * @param list<array{key: string, messages: list<string>}> $errors the venues that failed
     */
    public function __construct(
        public string $sourcePath,
        public ?string $onlyKey,
        public bool $pruned,
        public bool $forced,
        public bool $overwroteCmsEdits,
        public int $venuesSeen,
        public int $venuesCreated,
        public int $venuesUpdated,
        public int $venuesUnchanged,
        public int $venuesFailed,
        public int $venuesUnpublished,
        public int $venuesCmsEdited,
        public int $photosAdded,
        public int $photosRemoved,
        public int $documentsAdded,
        public int $documentsRemoved,
        public array $errors,
        public \DateTimeImmutable $startedAt,
        public \DateTimeImmutable $finishedAt,
    ) {
    }
}
