<?php

declare(strict_types=1);

namespace App\Stats\ReadModel;

use App\Shared\Funnel\FunnelEvent;

/**
 * The scorecard's figure per funnel step for one week, one campaign or the whole window (G8): distinct venues for a
 * venue step, rows for an inquiry or a brief. A step without events is 0.
 */
final readonly class FunnelCounts
{
    /**
     * @param array<string, int> $byEvent the count per `FunnelEvent` value
     */
    public function __construct(
        private array $byEvent = [],
    ) {
    }

    public function of(FunnelEvent $event): int
    {
        return $this->byEvent[$event->value] ?? 0;
    }

    public function total(): int
    {
        return array_sum($this->byEvent);
    }
}
