<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Saves a room's fields (#25, CMS-5, D23): the whole state of the room editor's drawer, with the
 * same rules as AddRoom. Only the locales in `names`/`descriptions` change; a non-default locale
 * with an empty name loses its row. A seating type left out is no longer offered. A content
 * room keeps its content_key. `loadedAt` is the room's row token (RowTokens::sql() of
 * `rooms.updated_at`). An unchanged save changes nothing.
 */
final readonly class ChangeRoom implements AuditableCommand
{
    /**
     * @param array<string, string>                      $names        locale → name
     * @param array<string, ?string>                     $descriptions locale → description
     * @param array<string, array{min: ?int, max: ?int}> $seating      Seating value → range
     */
    public function __construct(
        public int $venueNumber,
        public string $roomId,
        public string $loadedAt,
        public array $names,
        public array $descriptions,
        public ?int $capacityMin,
        public ?int $capacityMax,
        public ?int $areaM2,
        public ?string $heightM,
        public ?string $floorMaterial,
        public array $seating,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['room_id' => $this->roomId, 'locales' => array_keys($this->names)];
    }
}
