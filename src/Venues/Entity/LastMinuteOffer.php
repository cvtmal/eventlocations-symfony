<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A short-notice offer of a Pro venue (G14, «Kurzfristig frei», D55): one day or a period, optionally one room, the
 * event types it suits, a price or package text, a minimum of guests and terms. It is visible through its last day
 * (`endsOn`, a Zurich calendar day) and deleted by the nightly `app:last-minute:expire` after that. There is no edit:
 * a manager ends an offer and publishes a new one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'last_minute_offers')]
#[ORM\Index(name: 'idx_last_minute_offers_venue_id', columns: ['venue_id'])]
#[ORM\Index(name: 'idx_last_minute_offers_room_id', columns: ['room_id'])]
#[ORM\Index(name: 'idx_last_minute_offers_ends_on', columns: ['ends_on'])]
class LastMinuteOffer
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', nullable: false, onDelete: 'CASCADE')]
    private Venue $venue;

    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(name: 'room_id', nullable: true, onDelete: 'CASCADE')]
    private ?Room $room;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startsOn;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endsOn;

    /** @var list<string> `suitable_for` codes */
    #[ORM\Column(type: Types::JSONB, options: ['default' => '[]'])]
    private array $eventTypes;

    #[ORM\Column(type: Types::STRING, length: 150)]
    private string $priceText;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $minGuests;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $terms;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<string> $eventTypes
     */
    public function __construct(
        Venue $venue,
        ?Room $room,
        \DateTimeImmutable $startsOn,
        \DateTimeImmutable $endsOn,
        array $eventTypes,
        string $priceText,
        ?int $minGuests,
        ?string $terms,
        \DateTimeImmutable $now,
    ) {
        $this->id = EntityId::generate();
        $this->venue = $venue;
        $this->room = $room;
        $this->startsOn = $startsOn;
        $this->endsOn = $endsOn;
        $this->eventTypes = $eventTypes;
        $this->priceText = $priceText;
        $this->minGuests = $minGuests;
        $this->terms = $terms;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        \assert(null !== $this->id);

        return $this->id;
    }

    public function venue(): Venue
    {
        return $this->venue;
    }

    public function room(): ?Room
    {
        return $this->room;
    }

    public function startsOn(): \DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function endsOn(): \DateTimeImmutable
    {
        return $this->endsOn;
    }

    /**
     * @return list<string>
     */
    public function eventTypes(): array
    {
        return $this->eventTypes;
    }

    public function priceText(): string
    {
        return $this->priceText;
    }

    public function minGuests(): ?int
    {
        return $this->minGuests;
    }

    public function terms(): ?string
    {
        return $this->terms;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
