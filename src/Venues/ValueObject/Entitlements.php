<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * What a venue may show and do at one instant (F12, D15): the limits and flags of the tier that applies to it. Built
 * only by `EntitlementResolver` (G9 changes where the tier comes from, not this shape), read by every consumer:
 * detail page, cards, Merkliste, JSON-LD, mails, upload checks, ranking label.
 *
 * - `photosMax`, `roomsMax`, `documentsMax`: the first N by sort order are public, the rest stay stored and hidden.
 * - `videosMax`: reserved for the video embeds of G15.
 * - `contactVisible`: the venue's phone and website; the inquiry form and the address are always public.
 * - `eventTypesMax`: NULL means every event type. It is checked when staff or managers write the types, never
 *   when a page is read, so a venue that fell back to Free keeps its list until someone edits it.
 */
final readonly class Entitlements
{
    public function __construct(
        public string $tierCode,
        public string $tierName,
        public int $rankWeight,
        public int $photosMax,
        public int $roomsMax,
        public int $videosMax,
        public int $documentsMax,
        public bool $contactVisible,
        public bool $lastMinuteAllowed,
        public bool $detailedReport,
        public ?int $eventTypesMax,
    ) {
    }

    /**
     * @param array<string, mixed> $row the `tiers` columns, selected through `EntitlementResolver::COLUMNS`
     */
    public static function fromRow(array $row): self
    {
        \assert(\is_string($row['tier_code']) && \is_string($row['tier_name']));
        \assert(\is_int($row['rank_weight']) && \is_int($row['photos_max']) && \is_int($row['rooms_max']));
        \assert(\is_int($row['videos_max']) && \is_int($row['documents_max']));
        \assert(null === $row['event_types_max'] || \is_int($row['event_types_max']));

        return new self(
            $row['tier_code'],
            $row['tier_name'],
            $row['rank_weight'],
            $row['photos_max'],
            $row['rooms_max'],
            $row['videos_max'],
            $row['documents_max'],
            (bool) $row['contact_visible'],
            (bool) $row['last_minute_allowed'],
            (bool) $row['detailed_report'],
            $row['event_types_max'],
        );
    }

    /**
     * A listing above the unboosted ones carries the "Top-Eintrag" label (weight 0 is no boost).
     */
    public function isPromoted(): bool
    {
        return $this->rankWeight > 0;
    }

    public function allowsEventTypes(int $count): bool
    {
        return null === $this->eventTypesMax || $count <= $this->eventTypesMax;
    }
}
