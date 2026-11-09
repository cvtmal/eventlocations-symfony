<?php

declare(strict_types=1);

namespace App\Shared\Command;

use App\Shared\Funnel\FunnelEvent;

/**
 * Appends one row to the funnel scorecard's event log (G8). Any module may dispatch it (it is a Shared
 * command, so Venues, Inquiries, Crm, Portal and Seo need no Stats dependency); `Stats\CommandHandler\
 * RecordFunnelEventHandler` handles it.
 *
 * `venueId` is the venue's UUID (`Venue::id()`), null for a step without one (a brief to several
 * venues). `campaignCode` is the campaign of the outreach that led here (a `CampaignCode`: letters, digits, `_`, `-`,
 * at most 40, starting with a letter or digit), null when the publisher knows none: the scorecard then
 * uses the first campaign recorded for that venue before the step. The code names a campaign the staff defined; never
 * take it from a visitor's request. The time is the handler's clock, or `occurredAt` when given (a step put back at
 * the time it first happened, G7: an unmarked inquiry's delivery). Never put personal data in either field: the
 * handler drops (and logs) a venue id that is no UUID and a campaign code that is not a plain code, and records the
 * step without it.
 *
 * Recording is best effort: the handler never throws. Inside a command handler dispatch with
 * `new DispatchAfterCurrentBusStamp()`, so the row is written after your commit and only if it
 * happened; from a controller or console command dispatch plainly.
 */
final readonly class RecordFunnelEvent
{
    public function __construct(
        public FunnelEvent $event,
        public ?string $venueId = null,
        public ?string $campaignCode = null,
        public ?\DateTimeImmutable $occurredAt = null,
    ) {
    }
}
