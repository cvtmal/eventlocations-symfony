<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Publishes an offer «Kurzfristig frei» of a venue (G14, D55); the handler returns its id. The venue's plan must allow
 * it (Pro); the first day lies between today and 90 days ahead (Zurich), the last day (null = the first) at most 13
 * days after it. The room (null = the whole location) is one the venue's page shows; the event types are codes the
 * venue lists under «Geeignet für». Texts are trimmed; empty terms are none.
 */
final readonly class PublishLastMinuteOffer implements AuditableCommand
{
    /**
     * @param list<string> $eventTypes `suitable_for` codes
     */
    public function __construct(
        public int $venueNumber,
        public \DateTimeImmutable $startsOn,
        public ?\DateTimeImmutable $endsOn,
        public ?string $roomId,
        public array $eventTypes,
        public string $priceText,
        public ?int $minGuests,
        public ?string $terms,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return [
            'starts_on' => $this->startsOn->format('Y-m-d'),
            'ends_on' => ($this->endsOn ?? $this->startsOn)->format('Y-m-d'),
            'room' => null !== $this->roomId,
            'event_types' => $this->eventTypes,
        ];
    }
}
