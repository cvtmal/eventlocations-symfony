<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use App\Crm\Repository\InquiryLinkRepository;
use App\Crm\ValueObject\InquiryStatus;
use App\Inquiries\Entity\Inquiry;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What the CRM made of one inquiry (CRM-9): its timeline entry and its inbox status. The couple is
 * no CRM contact (#55); its name and address are read live from `inquiries`, never copied here. The
 * row goes with the inquiry (CASCADE) and outlives its entry (SET NULL), because "no link row" means
 * "never ingested". An inquiry for a venue without a Location gets a row with no entry (D5).
 */
#[ORM\Entity(repositoryClass: InquiryLinkRepository::class)]
#[ORM\Table(name: 'crm_inquiry_links')]
#[ORM\Index(name: 'idx_crm_inquiry_links_status', columns: ['status'])]
#[ORM\Index(name: 'idx_crm_inquiry_links_activity_id', columns: ['activity_id'])]
class InquiryLink
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Inquiry::class)]
    #[ORM\JoinColumn(name: 'inquiry_id', nullable: false, onDelete: 'CASCADE')]
    private Inquiry $inquiry;

    #[ORM\ManyToOne(targetEntity: Activity::class)]
    #[ORM\JoinColumn(name: 'activity_id', nullable: true, onDelete: 'SET NULL')]
    private ?Activity $activity = null;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: InquiryStatus::class, options: ['default' => 'new'])]
    private InquiryStatus $status = InquiryStatus::New;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Inquiry $inquiry, ?Activity $activity)
    {
        $this->inquiry = $inquiry;
        $this->activity = $activity;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function inquiry(): Inquiry
    {
        return $this->inquiry;
    }

    public function activity(): ?Activity
    {
        return $this->activity;
    }

    public function status(): InquiryStatus
    {
        return $this->status;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function changeStatus(InquiryStatus $status): void
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
