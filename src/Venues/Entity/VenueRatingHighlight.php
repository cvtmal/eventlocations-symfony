<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Venues\ValueObject\RatingHighlight;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One of a venue's up to three rating highlights (DP-6), at position 1–3; each highlight
 * at most once per venue.
 */
#[ORM\Entity]
#[ORM\Table(name: 'venue_rating_highlights')]
#[ORM\Index(name: 'idx_venue_rating_highlights_venue_id', columns: ['venue_id'])]
#[ORM\UniqueConstraint(name: 'uk_venue_rating_highlights_venue_id_code', columns: ['venue_id', 'code'])]
class VenueRatingHighlight
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', nullable: false, onDelete: 'CASCADE')]
    private Venue $venue;

    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: RatingHighlight::class)]
    private RatingHighlight $code;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Venue $venue, int $position, RatingHighlight $code)
    {
        $this->venue = $venue;
        $this->position = $position;
        $this->code = $code;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function venue(): Venue
    {
        return $this->venue;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function code(): RatingHighlight
    {
        return $this->code;
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
