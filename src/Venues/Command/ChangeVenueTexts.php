<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * The CMS card "Texte" (#24): summary, description, meta description and parking text per
 * locale. The slug is not touched.
 */
final readonly class ChangeVenueTexts implements AuditableCommand
{
    /**
     * @param list<string>                                                                                                $changedFields
     * @param array<string, array{summary: string, description: ?string, metaDescription: ?string, parkingText: ?string}> $texts         locale → texts
     */
    public function __construct(
        public int $venueNumber,
        public string $loadedAt,
        public array $changedFields,
        public array $texts,
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
