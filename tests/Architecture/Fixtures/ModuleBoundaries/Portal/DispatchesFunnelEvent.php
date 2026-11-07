<?php

declare(strict_types=1);

namespace App\Portal\Probe;

use App\Shared\Command\RecordFunnelEvent;
use App\Shared\Funnel\FunnelEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Exists only for ModuleBoundariesProbeTest: the allowed edge Portal → Shared (the funnel command, G8).
 */
final readonly class DispatchesFunnelEvent
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function claimStarted(string $venueId, string $campaign): void
    {
        $this->bus->dispatch(new RecordFunnelEvent(FunnelEvent::ClaimStarted, $venueId, $campaign), [new DispatchAfterCurrentBusStamp()]);
    }
}
