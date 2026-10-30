<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * The result of importing one content folder. In a dry run the status and counts say what
 * the import would do.
 */
final readonly class VenueImportOutcome
{
    /**
     * @param list<string> $messages why the venue failed (empty otherwise)
     */
    private function __construct(
        public string $key,
        public ImportStatus $status,
        public bool $dryRun,
        public int $photosAdded = 0,
        public int $photosRemoved = 0,
        public int $documentsAdded = 0,
        public int $documentsRemoved = 0,
        public array $messages = [],
    ) {
    }

    public static function created(string $key, bool $dryRun, int $photosAdded, int $documentsAdded): self
    {
        return new self($key, ImportStatus::Created, $dryRun, $photosAdded, 0, $documentsAdded, 0);
    }

    public static function updated(string $key, bool $dryRun, int $photosAdded, int $photosRemoved, int $documentsAdded, int $documentsRemoved): self
    {
        return new self($key, ImportStatus::Updated, $dryRun, $photosAdded, $photosRemoved, $documentsAdded, $documentsRemoved);
    }

    public static function unchanged(string $key, bool $dryRun): self
    {
        return new self($key, ImportStatus::Unchanged, $dryRun);
    }

    /**
     * The folder was not applied: the CMS edited the venue since its last import, or deleted
     * it (#24, S8).
     */
    public static function cmsEdited(string $key, bool $dryRun): self
    {
        return new self($key, ImportStatus::CmsEdited, $dryRun);
    }

    /**
     * @param list<string> $messages
     */
    public static function failed(string $key, bool $dryRun, array $messages): self
    {
        return new self($key, ImportStatus::Failed, $dryRun, messages: $messages);
    }
}
