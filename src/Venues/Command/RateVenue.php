<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;
use App\Venues\ValueObject\RatingHighlight;

/**
 * The CMS card "Bewertung" (#24): score, count and up to three highlights in order. Both
 * NULL for an unrated venue. Reviews are #25's.
 */
final readonly class RateVenue implements AuditableCommand
{
    /**
     * @param list<string>          $changedFields
     * @param string|null           $score         one decimal ('8.4')
     * @param list<RatingHighlight> $highlights    in position order
     */
    public function __construct(
        public int $venueNumber,
        public string $loadedAt,
        public array $changedFields,
        public ?string $score,
        public ?int $count,
        public array $highlights,
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
