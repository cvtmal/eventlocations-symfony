<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Shared\Uid\EntityId;
use App\Venues\ValueObject\CandidateOrigin;
use App\Venues\ValueObject\CandidateStatus;
use App\Venues\ValueObject\ImageCandidate;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A reference to a picture on a venue's own website, found by an import (G3). The picture was not downloaded and is
 * not ours to show: the row is a pointer for staff and for the claim flow (G6), where the claimant ticks the ones
 * they own and declares the rights. No route, template or cache reads this table (D16).
 */
#[ORM\Entity]
#[ORM\Table(name: 'photo_candidates')]
#[ORM\Index(name: 'idx_photo_candidates_source_record_id', columns: ['source_record_id'])]
#[ORM\UniqueConstraint(name: 'uk_photo_candidates_source_record_id_image_url', columns: ['source_record_id', 'image_url'])]
class PhotoCandidate
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: SourceRecord::class)]
    #[ORM\JoinColumn(name: 'source_record_id', nullable: false, onDelete: 'CASCADE')]
    private SourceRecord $sourceRecord;

    #[ORM\Column(type: Types::STRING, length: 2000)]
    private string $imageUrl;

    #[ORM\Column(type: Types::STRING, length: 2000)]
    private string $pageUrl;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: CandidateOrigin::class)]
    private CandidateOrigin $origin;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $alt;

    #[ORM\Column(type: Types::STRING, length: 200, nullable: true)]
    private ?string $context;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $width;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $height;

    /** Not measured by the importer; reserved for a later cycle that fetches the file (G6). */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $bytes = null;

    /** The order the importer found the references in, from 1. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: CandidateStatus::class, options: ['default' => 'candidate'])]
    private CandidateStatus $status;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(SourceRecord $sourceRecord, ImageCandidate $image, int $position)
    {
        $this->id = EntityId::generate();
        $this->sourceRecord = $sourceRecord;
        $this->imageUrl = $image->imageUrl;
        $this->pageUrl = $image->pageUrl;
        $this->origin = $image->origin;
        $this->alt = $image->alt;
        $this->context = $image->context;
        $this->width = $image->width;
        $this->height = $image->height;
        $this->position = $position;
        $this->status = CandidateStatus::Candidate;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string
    {
        \assert(null !== $this->id);

        return $this->id;
    }

    public function sourceRecord(): SourceRecord
    {
        return $this->sourceRecord;
    }

    public function imageUrl(): string
    {
        return $this->imageUrl;
    }

    public function pageUrl(): string
    {
        return $this->pageUrl;
    }

    public function origin(): CandidateOrigin
    {
        return $this->origin;
    }

    public function alt(): ?string
    {
        return $this->alt;
    }

    public function context(): ?string
    {
        return $this->context;
    }

    public function width(): ?int
    {
        return $this->width;
    }

    public function height(): ?int
    {
        return $this->height;
    }

    public function bytes(): ?int
    {
        return $this->bytes;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function status(): CandidateStatus
    {
        return $this->status;
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
