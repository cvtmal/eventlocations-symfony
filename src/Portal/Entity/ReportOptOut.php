<?php

declare(strict_types=1);

namespace App\Portal\Entity;

use App\Shared\Uid\EntityId;
use App\Venues\Entity\Venue;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A venue whose monthly report (G10) was turned off through the one-click link in the mail. The handlers write it
 * through DBAL (`ON CONFLICT DO NOTHING`, so a double click cannot fail); the mapping keeps `schema:validate` in sync
 * and serves the factory. The unique key on `venue_id` is the FK index too:
 * Doctrine treats a separate single-column index on it as redundant.
 */
#[ORM\Entity]
#[ORM\Table(name: 'portal_report_opt_outs')]
#[ORM\UniqueConstraint(name: 'uk_portal_report_opt_outs_venue_id', columns: ['venue_id'])]
class ReportOptOut
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', nullable: false, onDelete: 'CASCADE')]
    private Venue $venue;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $optedOutAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Venue $venue, \DateTimeImmutable $optedOutAt)
    {
        $this->id = EntityId::generate();
        $this->venue = $venue;
        $this->optedOutAt = $optedOutAt;
        $this->createdAt = $optedOutAt;
        $this->updatedAt = $optedOutAt;
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

    public function optedOutAt(): \DateTimeImmutable
    {
        return $this->optedOutAt;
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
