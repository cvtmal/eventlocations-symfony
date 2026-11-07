<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;
use App\Venues\ValueObject\PriceLevel;

/**
 * The CMS card "Veranstaltung" (#24, #31's facts, event-vocabulary F3): price level, seated and
 * standing capacity, the three event texts per locale and the three event term groups
 * (amenity, catering, language). A group missing from `terms` is emptied.
 */
final readonly class ChangeVenueEventFacts implements AuditableCommand
{
    /**
     * @param list<string>                                                                          $changedFields
     * @param array<string, array{ambience: ?string, capacityNote: ?string, openingHours: ?string}> $texts         locale → texts
     * @param array<string, list<string>>                                                           $terms         TermGroup value → selected codes
     */
    public function __construct(
        public int $venueNumber,
        public string $loadedAt,
        public array $changedFields,
        public ?PriceLevel $priceLevel,
        public ?int $capacitySeated,
        public ?int $capacityStanding,
        public array $texts,
        public array $terms,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['changed' => $this->changedFields];
    }
}
