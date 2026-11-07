<?php

declare(strict_types=1);

namespace App\Factory;

use App\Shared\Funnel\FunnelEvent;
use App\Stats\Entity\RecordedFunnelEvent;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A delivered inquiry of no venue and no campaign, now. Tests and `FunnelStory` set the step, the time, the venue
 * UUID and the campaign they need.
 *
 * @extends PersistentObjectFactory<RecordedFunnelEvent>
 */
final class RecordedFunnelEventFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return RecordedFunnelEvent::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'event' => FunnelEvent::InquiryDelivered,
            'occurredAt' => new \DateTimeImmutable(),
            'venueId' => null,
            'campaignCode' => null,
        ];
    }
}
