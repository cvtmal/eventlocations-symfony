<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * The CMS card "Adresse" (#24, D1–D4): "PLZ / Ort" as typed ("8706 Meilen"), the canton for a
 * venue without a town (and to pick among towns that share ZIP and name), street,
 * coordinates, the three distances and the region text of a venue without a canton.
 *
 * `useTownCentre` is the "Ortsmitte übernehmen" button: the coordinates become the town's
 * centre and are flagged as such (coordinates_from_town); typed or cleared coordinates drop
 * the flag.
 */
final readonly class RelocateVenue implements AuditableCommand
{
    /**
     * @param list<string>               $changedFields
     * @param string|null                $town          "<zip> <name>", null for no town
     * @param string|null                $cantonCode    lower-case canton code
     * @param string|null                $stationKm     decimal string ('2.5'), as the other distances
     * @param array<string, string|null> $regions       locale → region text
     * @param bool                       $regionCleared the region the form showed goes, because a town or canton now exists (summary only)
     */
    public function __construct(
        public int $venueNumber,
        public string $loadedAt,
        public array $changedFields,
        public ?string $town,
        public ?string $cantonCode,
        public ?string $street,
        public ?float $lat,
        public ?float $lng,
        public ?string $stationKm,
        public ?string $stationName,
        public ?string $highwayKm,
        public ?string $highwayName,
        public ?string $airportKm,
        public ?string $airportName,
        public array $regions,
        public bool $useTownCentre = false,
        public bool $regionCleared = false,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        $summary = ['changed' => $this->changedFields];
        if ($this->useTownCentre) {
            $summary['town_centre'] = true;
        }
        if ($this->regionCleared) {
            $summary['region_cleared'] = true;
        }

        return $summary;
    }
}
