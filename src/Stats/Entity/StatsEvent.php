<?php

declare(strict_types=1);

namespace App\Stats\Entity;

use App\Shared\Uid\EntityId;
use App\Stats\ValueObject\ClickKind;
use App\Stats\ValueObject\EventType;
use App\Stats\ValueObject\PageType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One raw event from the spool (#47, contracts C2 and C4), kept forever. `venues` holds venue numbers,
 * not foreign keys, so a row keeps its history when a venue goes. Mapping only: written by
 * `IngestStatsHandler` with DBAL multi-row inserts. Append-only, so no updated_at.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stats_events')]
#[ORM\Index(name: 'idx_stats_events_occurred_at', columns: ['occurred_at'])]
class StatsEvent
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: EventType::class)]
    private EventType $type;

    #[ORM\Column(type: Types::STRING, length: 10, nullable: true, enumType: ClickKind::class)]
    private ?ClickKind $kind;

    #[ORM\Column(type: Types::STRING, length: 10, nullable: true, enumType: PageType::class)]
    private ?PageType $page;

    /** @var list<int>|null */
    #[ORM\Column(type: Types::JSONB, nullable: true)]
    private ?array $venues;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $path;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $referrerHost;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $userAgent;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $visitorHash;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $automated;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<int>|null $venues
     */
    public function __construct(
        \DateTimeImmutable $occurredAt,
        EventType $type,
        ?ClickKind $kind,
        ?PageType $page,
        ?array $venues,
        ?string $path,
        ?string $referrerHost,
        ?string $userAgent,
        ?string $visitorHash,
        bool $automated,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = EntityId::generate();
        $this->occurredAt = $occurredAt;
        $this->type = $type;
        $this->kind = $kind;
        $this->page = $page;
        $this->venues = $venues;
        $this->path = $path;
        $this->referrerHost = $referrerHost;
        $this->userAgent = $userAgent;
        $this->visitorHash = $visitorHash;
        $this->automated = $automated;
        $this->createdAt = $createdAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function type(): EventType
    {
        return $this->type;
    }

    public function kind(): ?ClickKind
    {
        return $this->kind;
    }

    public function page(): ?PageType
    {
        return $this->page;
    }

    /**
     * @return list<int>|null
     */
    public function venues(): ?array
    {
        return $this->venues;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    public function referrerHost(): ?string
    {
        return $this->referrerHost;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }

    public function visitorHash(): ?string
    {
        return $this->visitorHash;
    }

    public function automated(): bool
    {
        return $this->automated;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
