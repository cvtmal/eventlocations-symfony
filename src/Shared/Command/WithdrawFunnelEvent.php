<?php

declare(strict_types=1);

namespace App\Shared\Command;

use App\Shared\Funnel\FunnelEvent;

/**
 * Takes back one step of the funnel scorecard's event log (G8) that should never have been recorded: a payment that
 * staff voided (G9 finish), so the scorecard counts no paying venue that never paid (D18). Removes the one row of
 * `event` for `venueId` recorded nearest to `recordedAt`, within an hour of it (the publisher's time of the original step; the log's own
 * time is the handler's clock, a moment later). `Stats\CommandHandler\WithdrawFunnelEventHandler` handles it.
 *
 * Best effort like `RecordFunnelEvent`: the handler never throws. Inside a command handler dispatch with
 * `new DispatchAfterCurrentBusStamp()`, so the row goes only after your commit.
 */
final readonly class WithdrawFunnelEvent
{
    public function __construct(
        public FunnelEvent $event,
        public string $venueId,
        public \DateTimeImmutable $recordedAt,
    ) {
    }
}
