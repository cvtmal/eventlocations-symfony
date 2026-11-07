<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Shared\Uid\EntityId;
use App\Venues\ValueObject\SourceStatus;
use App\Venues\ValueObject\SourceType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One import of an outside source for a venue (G3): what a crawl of the venue's own website found, as facts with
 * their confidence and source page, plus a report of what was fetched and refused. Private working data: no route,
 * template, sitemap or cache reads this table, and nothing in it is copied into a public field (D16). The photo
 * references of the same crawl are `photo_candidates`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'source_records')]
#[ORM\Index(name: 'idx_source_records_venue_id', columns: ['venue_id'])]
#[ORM\UniqueConstraint(name: 'uk_source_records_url_fetched', columns: ['url'], options: ['where' => "((status)::text = 'fetched'::text)"])]
class SourceRecord
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', nullable: true, onDelete: 'CASCADE')]
    private ?Venue $venue;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: SourceType::class)]
    private SourceType $sourceType;

    #[ORM\Column(type: Types::STRING, length: 2000)]
    private string $url;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: SourceStatus::class, options: ['default' => 'fetched'])]
    private SourceStatus $status;

    /** @var array<string, list<array{value: mixed, confidence: float, source_url: string, via: string}>> */
    #[ORM\Column(type: Types::JSONB)]
    private array $facts;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSONB)]
    private array $report;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $fetchedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, list<array{value: mixed, confidence: float, source_url: string, via: string}>> $facts
     * @param array<string, mixed>                                                                         $report
     */
    public function __construct(?Venue $venue, SourceType $sourceType, string $url, array $facts, array $report, \DateTimeImmutable $fetchedAt)
    {
        $this->id = EntityId::generate();
        $this->venue = $venue;
        $this->sourceType = $sourceType;
        $this->url = $url;
        $this->status = SourceStatus::Fetched;
        $this->facts = $facts;
        $this->report = $report;
        $this->fetchedAt = $fetchedAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string
    {
        \assert(null !== $this->id);

        return $this->id;
    }

    public function venue(): ?Venue
    {
        return $this->venue;
    }

    public function sourceType(): SourceType
    {
        return $this->sourceType;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function status(): SourceStatus
    {
        return $this->status;
    }

    /**
     * @return array<string, list<array{value: mixed, confidence: float, source_url: string, via: string}>>
     */
    public function facts(): array
    {
        return $this->facts;
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return $this->report;
    }

    public function fetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
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
