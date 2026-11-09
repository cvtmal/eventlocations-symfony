<?php

declare(strict_types=1);

namespace App\Inquiries\Entity;

use App\Inquiries\ValueObject\FeedbackOutcome;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The feedback mail of one inquiry and the guest's answer (G16). Created when the nightly job handles the inquiry:
 * `mailed()` after the mail went out, `skipped()` when the guest's address had opted out (the row carries that
 * opt-out on, so it lasts as long as the address has a stored inquiry). Deleted with its inquiry (LEG-2).
 */
#[ORM\Entity]
#[ORM\Table(name: 'inquiry_feedback')]
#[ORM\Index(name: 'idx_inquiry_feedback_answered_at', columns: ['answered_at'])]
class InquiryFeedback
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Inquiry::class)]
    #[ORM\JoinColumn(name: 'inquiry_id', nullable: false, onDelete: 'CASCADE')]
    private Inquiry $inquiry;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $mailedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $optedOutAt = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $venueReplied = null;

    #[ORM\Column(type: Types::STRING, length: 4, nullable: true, enumType: FeedbackOutcome::class)]
    private ?FeedbackOutcome $outcome = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $answeredAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Inquiry $inquiry, \DateTimeImmutable $at)
    {
        $this->inquiry = $inquiry;
        $this->createdAt = $at;
        $this->updatedAt = $at;
    }

    public static function mailed(Inquiry $inquiry, \DateTimeImmutable $at): self
    {
        $feedback = new self($inquiry, $at);
        $feedback->mailedAt = $at;

        return $feedback;
    }

    public static function skipped(Inquiry $inquiry, \DateTimeImmutable $at): self
    {
        $feedback = new self($inquiry, $at);
        $feedback->optedOutAt = $at;

        return $feedback;
    }

    /**
     * Stores (or changes) the answer; `answeredAt` keeps the first one, so a change does not move it to another month.
     */
    public function answer(bool $venueReplied, FeedbackOutcome $outcome, \DateTimeImmutable $at): void
    {
        $this->venueReplied = $venueReplied;
        $this->outcome = $outcome;
        $this->answeredAt ??= $at;
        $this->updatedAt = $at;
    }

    public function optOut(\DateTimeImmutable $at): void
    {
        $this->optedOutAt ??= $at;
        $this->updatedAt = $at;
    }

    public function inquiry(): Inquiry
    {
        return $this->inquiry;
    }

    public function wasMailed(): bool
    {
        return null !== $this->mailedAt;
    }

    public function isOptedOut(): bool
    {
        return null !== $this->optedOutAt;
    }

    public function venueReplied(): ?bool
    {
        return $this->venueReplied;
    }

    public function outcome(): ?FeedbackOutcome
    {
        return $this->outcome;
    }

    public function answeredAt(): ?\DateTimeImmutable
    {
        return $this->answeredAt;
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
