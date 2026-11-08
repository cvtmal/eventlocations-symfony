<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Shared\Uid\EntityId;
use App\Venues\ValueObject\VideoEmbed;
use App\Venues\ValueObject\VideoProvider;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A YouTube or Vimeo video of a venue (G15), ordered by position. Only the provider and its video id are stored
 * (`VideoEmbed`); the page builds the player address from them and loads it on the visitor's click only.
 */
#[ORM\Entity]
#[ORM\Table(name: 'venue_videos')]
#[ORM\Index(name: 'idx_venue_videos_venue_id', columns: ['venue_id'])]
#[ORM\UniqueConstraint(name: 'uk_venue_videos_venue_id_position', columns: ['venue_id', 'position'])]
#[ORM\UniqueConstraint(name: 'uk_venue_videos_venue_id_provider_video_id', columns: ['venue_id', 'provider', 'video_id'])]
class VenueVideo
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', nullable: false, onDelete: 'CASCADE')]
    private Venue $venue;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: VideoProvider::class)]
    private VideoProvider $provider;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $videoId;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Venue $venue, int $position, VideoEmbed $embed)
    {
        $this->id = EntityId::generate();
        $this->venue = $venue;
        $this->position = $position;
        $this->provider = $embed->provider;
        $this->videoId = $embed->videoId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
        $this->updatedAt = new \DateTimeImmutable();
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

    public function position(): int
    {
        return $this->position;
    }

    public function provider(): VideoProvider
    {
        return $this->provider;
    }

    public function videoId(): string
    {
        return $this->videoId;
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
