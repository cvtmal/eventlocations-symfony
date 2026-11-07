<?php

declare(strict_types=1);

namespace App\Stats\Entity;

use App\Shared\Funnel\FunnelEvent;
use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One step of the funnel (G8), append-only. Mapping only (plus the Foundry factory and story): the
 * handler writes with DBAL, so a failed insert never closes the entity manager of the request that
 * published the event. `venueId` is no foreign key, so a row keeps its history when a venue goes. No
 * personal data: the campaign code is held to a plain code by `chk_funnel_events_campaign_code`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'funnel_events')]
#[ORM\Index(name: 'idx_funnel_events_event_occurred_at', columns: ['event', 'occurred_at'])]
#[ORM\Index(name: 'idx_funnel_events_campaign_code', columns: ['campaign_code'])]
#[ORM\Index(name: 'idx_funnel_events_venue_id_occurred_at', columns: ['venue_id', 'occurred_at'])]
class RecordedFunnelEvent
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: FunnelEvent::class)]
    private FunnelEvent $event;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $venueId;

    #[ORM\Column(type: Types::STRING, length: 40, nullable: true)]
    private ?string $campaignCode;

    public function __construct(FunnelEvent $event, \DateTimeImmutable $occurredAt, ?string $venueId = null, ?string $campaignCode = null)
    {
        $this->id = EntityId::generate();
        $this->event = $event;
        $this->occurredAt = $occurredAt;
        $this->venueId = $venueId;
        $this->campaignCode = $campaignCode;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function event(): FunnelEvent
    {
        return $this->event;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function venueId(): ?string
    {
        return $this->venueId;
    }

    public function campaignCode(): ?string
    {
        return $this->campaignCode;
    }
}
