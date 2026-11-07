<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\TimelineCursor;

final readonly class TimelinePage
{
    /**
     * @param list<TimelineEntry> $entries
     * @param ?TimelineCursor     $next    set when older entries exist
     */
    public function __construct(
        public array $entries,
        public ?TimelineCursor $next,
    ) {
    }

    /**
     * The island's first page (P6) and the answer of the timeline endpoints.
     *
     * @param list<string> $composableTypes
     *
     * @return array{entries: list<array<string, mixed>>, next: ?string}
     */
    public function toArray(?string $viewerId, array $composableTypes): array
    {
        return [
            'entries' => array_map(static fn (TimelineEntry $entry): array => $entry->toArray($viewerId, $composableTypes), $this->entries),
            'next' => $this->next?->token(),
        ];
    }
}
