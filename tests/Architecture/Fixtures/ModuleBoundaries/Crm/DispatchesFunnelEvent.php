<?php

declare(strict_types=1);

namespace App\Crm\Probe;

use App\Shared\Command\RecordFunnelEvent;
use App\Shared\Funnel\FunnelEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Exists only for ModuleBoundariesProbeTest: the allowed edge Crm → Shared. Crm may not use Stats, and does not
 * need to, to record a funnel step (G8).
 */
final readonly class DispatchesFunnelEvent
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function proPaid(string $venueId): void
    {
        $this->bus->dispatch(new RecordFunnelEvent(FunnelEvent::ProPaid, $venueId), [new DispatchAfterCurrentBusStamp()]);
    }
}
