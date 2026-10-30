<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * The CMS card "Merkmale" (#24, D16): the seven filter groups (setting … surroundings) with
 * the main building and the main setting. A group missing from `terms` is emptied; the six
 * wedding groups are ChangeVenueWeddingFacts'.
 */
final readonly class ChangeVenueTerms implements AuditableCommand
{
    /**
     * @param list<string>                $changedFields
     * @param array<string, list<string>> $terms         TermGroup value → selected codes
     * @param array<string, string|null>  $main          TermGroup value → main code (building, setting)
     */
    public function __construct(
        public int $venueNumber,
        public string $loadedAt,
        public array $changedFields,
        public array $terms,
        public array $main,
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
