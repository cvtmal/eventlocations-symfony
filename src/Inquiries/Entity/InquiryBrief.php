<?php

declare(strict_types=1);

namespace App\Inquiries\Entity;

use App\Inquiries\ValueObject\MailStatus;
use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The parent of a brief to several venues (F13, D17). Each venue gets its own child `Inquiry` with the guest's
 * details, its own id and its own mail status; the brief keeps no personal data, only how many venues it went to,
 * the repeat fingerprint and the one confirmation the guest gets for all of them.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inquiry_briefs')]
#[ORM\Index(name: 'idx_inquiry_briefs_fingerprint_created_at', columns: ['fingerprint', 'created_at'])]
#[ORM\Index(name: 'idx_inquiry_briefs_created_at', columns: ['created_at'])]
class InquiryBrief
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $venueCount;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $fingerprint;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: MailStatus::class, options: ['default' => 'pending'])]
    private MailStatus $guestMailStatus = MailStatus::Pending;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $venueCount, string $fingerprint, \DateTimeImmutable $createdAt)
    {
        $this->id = EntityId::generate();
        $this->venueCount = $venueCount;
        $this->fingerprint = $fingerprint;
        $this->createdAt = $createdAt;
        $this->updatedAt = $createdAt;
    }

    public function recordGuestMail(MailStatus $status, \DateTimeImmutable $at): void
    {
        $this->guestMailStatus = $status;
        $this->updatedAt = $at;
    }

    public function id(): string
    {
        \assert(null !== $this->id);

        return $this->id;
    }

    public function venueCount(): int
    {
        return $this->venueCount;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function guestMailStatus(): MailStatus
    {
        return $this->guestMailStatus;
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
