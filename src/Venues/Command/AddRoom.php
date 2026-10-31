<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Adds a room at the end of the venue's rooms (#25, CMS-5, D23); the handler returns its id.
 * The room has no content_key (it is the CMS's, not the import's). The room facts of the venue
 * are recomputed (RoomSummary) and the listings purged (D19).
 *
 * Texts per enabled locale: a name is required in the default locale; a locale with an empty
 * name gets no row (and must have no description). `heightM` is a decimal string ('4.20'),
 * `floorMaterial` a FloorMaterial value, `seating` maps a Seating value to its range; a type
 * left out is not offered.
 */
final readonly class AddRoom implements AuditableCommand
{
    /**
     * @param array<string, string>                      $names        locale → name
     * @param array<string, ?string>                     $descriptions locale → description
     * @param array<string, array{min: ?int, max: ?int}> $seating      Seating value → range
     */
    public function __construct(
        public int $venueNumber,
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
        return ['locales' => array_keys($this->names)];
    }
}
