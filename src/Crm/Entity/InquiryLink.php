<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use App\Crm\Repository\InquiryLinkRepository;
use App\Crm\ValueObject\InquiryStatus;
use App\Inquiries\Entity\Inquiry;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What the CRM made of one inquiry (CRM-9): its contact, its timeline entry and its inbox status.
 * The row goes with the inquiry (CASCADE) and outlives an erased contact (SET NULL on both), because
 * "no link row" means "never ingested", and `app:crm:backfill-inquiries` must not bring a person back.
 * Inquiry data is read live from `inquiries`, never copied here.
 */
#[ORM\Entity(repositoryClass: InquiryLinkRepository::class)]
#[ORM\Table(name: 'crm_inquiry_links')]
#[ORM\Index(name: 'idx_crm_inquiry_links_status', columns: ['status'])]
#[ORM\Index(name: 'idx_crm_inquiry_links_contact_id', columns: ['contact_id'])]
#[ORM\Index(name: 'idx_crm_inquiry_links_activity_id', columns: ['activity_id'])]
class InquiryLink
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Inquiry::class)]
    #[ORM\JoinColumn(name: 'inquiry_id', nullable: false, onDelete: 'CASCADE')]
    private Inquiry $inquiry;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(name: 'contact_id', nullable: true, onDelete: 'SET NULL')]
    private ?Contact $contact = null;

    #[ORM\ManyToOne(targetEntity: Activity::class)]
    #[ORM\JoinColumn(name: 'activity_id', nullable: true, onDelete: 'SET NULL')]
    private ?Activity $activity = null;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: InquiryStatus::class, options: ['default' => 'new'])]
    private InquiryStatus $status = InquiryStatus::New;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Inquiry $inquiry, Contact $contact, Activity $activity)
    {
        $this->inquiry = $inquiry;
        $this->contact = $contact;
        $this->activity = $activity;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function inquiry(): Inquiry
    {
        return $this->inquiry;
    }

    public function contact(): ?Contact
    {
        return $this->contact;
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
