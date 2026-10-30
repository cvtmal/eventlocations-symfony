<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;
use App\Venues\ValueObject\OwnCatering;

/**
 * The CMS card "Häufige Fragen" (#24, DP-9): own catering and daylight; NULL means unknown.
 * The parking answer is text and belongs to ChangeVenueTexts.
 */
final readonly class AnswerVenueFaq implements AuditableCommand
{
    /**
     * @param list<string> $changedFields
     */
    public function __construct(
        public int $venueNumber,
        public string $loadedAt,
        public array $changedFields,
        public ?OwnCatering $ownCatering,
        public ?bool $daylight,
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
