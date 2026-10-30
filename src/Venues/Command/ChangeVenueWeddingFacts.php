<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;
use App\Venues\ValueObject\PartyAllowed;
use App\Venues\ValueObject\PriceLevel;

/**
 * The CMS card "Hochzeit" (#24, #31's owner facts): price level, seated and standing
 * capacity, party rules, the wedding texts per locale and the six wedding term groups
 * (ceremony … language). A group missing from `terms` is emptied.
 */
final readonly class ChangeVenueWeddingFacts implements AuditableCommand
{
    /**
     * @param list<string>                                                                                              $changedFields
     * @param string|null                                                                                               $curfewAt      `H:i`, only with $hasCurfew true
     * @param array<string, array{ambience: ?string, capacityNote: ?string, partyNote: ?string, openingHours: ?string}> $texts         locale → texts
     * @param array<string, list<string>>                                                                               $terms         TermGroup value → selected codes
     */
    public function __construct(
        public int $venueNumber,
        public string $loadedAt,
        public array $changedFields,
        public ?PriceLevel $priceLevel,
        public ?int $capacitySeated,
        public ?int $capacityStanding,
        public ?PartyAllowed $party,
        public ?bool $hasCurfew,
        public ?string $curfewAt,
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
