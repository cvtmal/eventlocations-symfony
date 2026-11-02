<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * The manager's card "Maximale Gästezahl" (#44, POR-8): the maximum alone. The minimum stays
 * with staff, ChangeVenueBasics sets both.
 */
final readonly class ChangeVenueCapacity implements AuditableCommand
{
    /**
     * @param list<string> $changedFields
     */
    public function __construct(
        public int $venueNumber,
        public string $loadedAt,
        public array $changedFields,
        public int $capacityMax,
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
