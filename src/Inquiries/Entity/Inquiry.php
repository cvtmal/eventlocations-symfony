<?php

declare(strict_types=1);

namespace App\Inquiries\Entity;

use App\Inquiries\ValueObject\MailStatus;
use App\Inquiries\ValueObject\Occasion;
use App\Shared\Uid\EntityId;
use App\Venues\Entity\Venue;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An inquiry from the detail page (IQ-1). The venue's number, name, slug and address are copied
 * in, so the row stays meaningful after the venue changes or is deleted (`venue` becomes null).
 * `recipientEmail` is always the venue's address; `deliveredTo` is where the mail really went.
 * `fingerprint` recognises a repeat (`InquiryFingerprint`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'inquiries')]
#[ORM\Index(name: 'idx_inquiries_venue_id', columns: ['venue_id'])]
#[ORM\Index(name: 'idx_inquiries_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_inquiries_fingerprint_created_at', columns: ['fingerprint', 'created_at'])]
class Inquiry
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', nullable: true, onDelete: 'SET NULL')]
    private ?Venue $venue = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $venueNumber;

    #[ORM\Column(type: Types::STRING, length: 150)]
    private string $venueName;

    #[ORM\Column(type: Types::STRING, length: 160)]
    private string $venueSlug;

    #[ORM\Column(type: Types::STRING, length: 254)]
    private string $recipientEmail;

    #[ORM\Column(type: Types::STRING, length: 254, nullable: true)]
    private ?string $deliveredTo = null;

    #[ORM\Column(type: Types::STRING, length: 2)]
    private string $locale;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: Occasion::class)]
    private Occasion $occasion;

    #[ORM\Column(type: Types::INTEGER)]
    private int $guestCount;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $eventDate;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $firstName;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $lastName;

    #[ORM\Column(type: Types::STRING, length: 254)]
    private string $email;

    #[ORM\Column(type: Types::STRING, length: 40, nullable: true)]
    private ?string $phone;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $fingerprint;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: MailStatus::class, options: ['default' => 'pending'])]
    private MailStatus $venueMailStatus = MailStatus::Pending;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: MailStatus::class, options: ['default' => 'pending'])]
    private MailStatus $guestMailStatus = MailStatus::Pending;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $venueMailSentAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    /**
     * @throws \LogicException when the venue has no inquiry address (the handler refuses that first)
     */
    public function __construct(
        Venue $venue,
        string $venueSlug,
        string $locale,
        Occasion $occasion,
        int $guestCount,
        ?\DateTimeImmutable $eventDate,
        string $message,
        string $firstName,
        string $lastName,
        string $email,
        ?string $phone,
        string $fingerprint,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = EntityId::generate();
        $this->venue = $venue;
        $this->venueNumber = $venue->number();
        $this->venueName = $venue->name();
        $this->venueSlug = $venueSlug;
        $this->recipientEmail = $venue->inquiryEmail() ?? throw new \LogicException('An inquiry needs a venue with an inquiry address.');
        $this->locale = $locale;
        $this->occasion = $occasion;
        $this->guestCount = $guestCount;
        $this->eventDate = $eventDate;
        $this->message = $message;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->email = $email;
        $this->phone = $phone;
        $this->fingerprint = $fingerprint;
        $this->createdAt = $createdAt;
        $this->updatedAt = $createdAt;
    }

    public function recordVenueMailSent(string $deliveredTo, \DateTimeImmutable $at): void
    {
        $this->venueMailStatus = MailStatus::Sent;
        $this->deliveredTo = $deliveredTo;
        $this->venueMailSentAt = $at;
        $this->updatedAt = $at;
    }

    public function recordVenueMailFailed(\DateTimeImmutable $at): void
    {
        $this->venueMailStatus = MailStatus::Failed;
        $this->updatedAt = $at;
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

    public function venue(): ?Venue
    {
        return $this->venue;
    }

    public function venueNumber(): int
    {
        return $this->venueNumber;
    }

    public function venueName(): string
    {
        return $this->venueName;
    }

    public function venueSlug(): string
    {
        return $this->venueSlug;
    }

    public function recipientEmail(): string
    {
        return $this->recipientEmail;
    }

    public function deliveredTo(): ?string
    {
        return $this->deliveredTo;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function occasion(): Occasion
    {
        return $this->occasion;
    }

    public function guestCount(): int
    {
        return $this->guestCount;
    }

    public function eventDate(): ?\DateTimeImmutable
    {
        return $this->eventDate;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function lastName(): string
    {
        return $this->lastName;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function venueMailStatus(): MailStatus
    {
        return $this->venueMailStatus;
    }

    public function guestMailStatus(): MailStatus
    {
        return $this->guestMailStatus;
    }

    public function venueMailSentAt(): ?\DateTimeImmutable
    {
        return $this->venueMailSentAt;
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
