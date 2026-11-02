<?php

declare(strict_types=1);

namespace App\Portal\Entity;

use App\Admin\Entity\AdminUser;
use App\Portal\Repository\SubmissionRepository;
use App\Portal\ValueObject\SubmissionStatus;
use App\Venues\Entity\Venue;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The review of one location a manager created (#45, POR-12, POR-13). `venues.status` stays out of it: this row says
 * where the review stands, the venue says whether it is online.
 */
#[ORM\Entity(repositoryClass: SubmissionRepository::class)]
#[ORM\Table(name: 'portal_submissions')]
#[ORM\Index(name: 'idx_portal_submissions_status', columns: ['status'])]
#[ORM\Index(name: 'idx_portal_submissions_submitted_by', columns: ['submitted_by'])]
#[ORM\Index(name: 'idx_portal_submissions_reviewed_by', columns: ['reviewed_by'])]
class Submission
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', nullable: false, onDelete: 'CASCADE')]
    private Venue $venue;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: SubmissionStatus::class, options: ['default' => 'draft'])]
    private SubmissionStatus $status = SubmissionStatus::Draft;

    #[ORM\ManyToOne(targetEntity: PortalUser::class)]
    #[ORM\JoinColumn(name: 'submitted_by', nullable: true, onDelete: 'SET NULL')]
    private ?PortalUser $submittedBy = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $rightsConfirmedAt = null;

    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(name: 'reviewed_by', nullable: true, onDelete: 'RESTRICT')]
    private ?AdminUser $reviewedBy = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\Column(type: Types::STRING, length: 1000, nullable: true)]
    private ?string $rejectReason = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Venue $venue, \DateTimeImmutable $now)
    {
        $this->venue = $venue;
        $this->createdAt = $now;
        $this->updatedAt = $this->createdAt;
    }

    public function venue(): Venue
    {
        return $this->venue;
    }

    public function status(): SubmissionStatus
    {
        return $this->status;
    }

    public function submittedBy(): ?PortalUser
    {
        return $this->submittedBy;
    }

    public function submittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function rightsConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->rightsConfirmedAt;
    }

    public function reviewedBy(): ?AdminUser
    {
        return $this->reviewedBy;
    }

    public function reviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function rejectReason(): ?string
    {
        return $this->rejectReason;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Sending it twice is no second review: the row stays as it is, and the caller fires nothing.
     */
    public function submit(PortalUser $by, \DateTimeImmutable $at): bool
    {
        if ($this->isSubmitted()) {
            return false;
        }

        $this->status = SubmissionStatus::Submitted;
        $this->submittedBy = $by;
        $this->submittedAt = $at;
        $this->rightsConfirmedAt = $at;
        $this->rejectReason = null;
        $this->updatedAt = $at;

        return true;
    }

    public function isSubmitted(): bool
    {
        return SubmissionStatus::Submitted === $this->status;
    }

    public function accept(AdminUser $by, \DateTimeImmutable $at): void
    {
        $this->status = SubmissionStatus::Accepted;
        $this->reviewedBy = $by;
        $this->reviewedAt = $at;
        $this->updatedAt = $at;
    }

    public function reject(AdminUser $by, string $reason, \DateTimeImmutable $at): void
    {
        $this->status = SubmissionStatus::Rejected;
        $this->reviewedBy = $by;
        $this->reviewedAt = $at;
        $this->rejectReason = $reason;
        $this->updatedAt = $at;
    }
}
