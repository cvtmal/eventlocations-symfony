<?php

declare(strict_types=1);

namespace App\Portal\Entity;

use App\Portal\ValueObject\PortalVenueAccessStatus;
use App\Venues\Entity\Venue;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A manager's access to a location (S16): pending from the request, active once confirmed. Both sides
 * cascade, so the row goes with the manager or the location.
 */
#[ORM\Entity]
#[ORM\Table(name: 'portal_venue_access')]
#[ORM\Index(name: 'idx_portal_venue_access_portal_user_id', columns: ['portal_user_id'])]
#[ORM\Index(name: 'idx_portal_venue_access_venue_id', columns: ['venue_id'])]
class PortalVenueAccess
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: PortalUser::class)]
    #[ORM\JoinColumn(name: 'portal_user_id', nullable: false, onDelete: 'CASCADE')]
    private PortalUser $user;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', nullable: false, onDelete: 'CASCADE')]
    private Venue $venue;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: PortalVenueAccessStatus::class)]
    private PortalVenueAccessStatus $status = PortalVenueAccessStatus::Pending;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(PortalUser $user, Venue $venue, \DateTimeImmutable $requestedAt)
    {
        $this->user = $user;
        $this->venue = $venue;
        $this->requestedAt = $requestedAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function user(): PortalUser
    {
        return $this->user;
    }

    public function venue(): Venue
    {
        return $this->venue;
    }

    public function status(): PortalVenueAccessStatus
    {
        return $this->status;
    }

    public function requestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function confirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function confirm(\DateTimeImmutable $at): void
    {
        $this->status = PortalVenueAccessStatus::Active;
        $this->confirmedAt = $at;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
