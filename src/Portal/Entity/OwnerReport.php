<?php

declare(strict_types=1);

namespace App\Portal\Entity;

use App\Shared\Uid\EntityId;
use App\Venues\Entity\Venue;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The marker of one monthly report mail (G10): the venue and the month's first day. Written once a mail went out, so
 * the second run of `app:portal:owner-reports` sends nothing; `uk_…_report_month` is the backstop. Rows older than
 * `RETENTION_MONTHS` go in the same nightly step (`PurgeOwnerReports`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'portal_owner_reports')]
#[ORM\Index(name: 'idx_portal_owner_reports_venue_id', columns: ['venue_id'])]
#[ORM\UniqueConstraint(name: 'uk_portal_owner_reports_venue_id_report_month', columns: ['venue_id', 'report_month'])]
class OwnerReport
{
    public const int RETENTION_MONTHS = 24;

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', nullable: false, onDelete: 'CASCADE')]
    private Venue $venue;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $reportMonth;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $sentAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Venue $venue, \DateTimeImmutable $reportMonth, \DateTimeImmutable $sentAt)
    {
        if ('01' !== $reportMonth->format('d')) {
            throw new \InvalidArgumentException(\sprintf('A report month is its first day, not %s.', $reportMonth->format('Y-m-d')));
        }
        $this->id = EntityId::generate();
        $this->venue = $venue;
        $this->reportMonth = $reportMonth;
        $this->sentAt = $sentAt;
        $this->createdAt = $sentAt;
        $this->updatedAt = $sentAt;
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

    public function reportMonth(): \DateTimeImmutable
    {
        return $this->reportMonth;
    }

    public function sentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
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
