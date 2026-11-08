<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * The CMS card "Grunddaten" (#24): name, slug and capacity range (the tier follows
 * the paid terms, G9). A rename moves the
 * slugs with history (VenueSlugs::assign()); a slug typed by hand goes through
 * VenueSlugs::change().
 */
final readonly class ChangeVenueBasics implements AuditableCommand
{
    /**
     * @param string       $loadedAt      `venues.updated_at` in Unix seconds when the form was rendered
     * @param list<string> $changedFields the card's fields that differ from what was loaded
     * @param string|null  $newSlug       the default locale's slug typed by hand; null when the field is unchanged
     */
    public function __construct(
        public int $venueNumber,
        public string $loadedAt,
        public array $changedFields,
        public string $name,
        public ?string $newSlug,
        public ?int $capacityMin,
        public int $capacityMax,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        $summary = ['changed' => $this->changedFields];
        if (null !== $this->newSlug) {
            $summary['slug'] = $this->newSlug;
        }

        return $summary;
    }
}
