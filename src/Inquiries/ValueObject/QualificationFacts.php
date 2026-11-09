<?php

declare(strict_types=1);

namespace App\Inquiries\ValueObject;

/**
 * What `QualificationRule` needs to know about one inquiry (G7, D19, D56), read by `QualificationFactsQuery` before
 * the venue mail; `withDelivered()` adds the mail's outcome after it.
 *
 * - `venueEventTypes`: the venue's `suitable_for` codes; empty when it lists none or is gone (then any type passes).
 * - `guestsFit`: the F5b guest rule (`VenueCapacity::guestsFitSql()`); true when the venue is gone.
 * - `previousDeliveredAt`: the creation time of the latest earlier inquiry to the same venue from the same address
 *   (case-insensitive) whose venue mail went out and that staff have not marked; null if there is none.
 */
final readonly class QualificationFacts
{
    /**
     * @param list<string> $venueEventTypes
     */
    public function __construct(
        public bool $delivered,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $eventDate,
        public string $eventType,
        public array $venueEventTypes,
        public bool $guestsFit,
        public ?\DateTimeImmutable $previousDeliveredAt,
    ) {
    }

    public function withDelivered(bool $delivered): self
    {
        return new self($delivered, $this->createdAt, $this->eventDate, $this->eventType, $this->venueEventTypes, $this->guestsFit, $this->previousDeliveredAt);
    }
}
