<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Venues\ValueObject\ModerationDecision;
use App\Venues\ValueObject\ModerationKind;
use App\Venues\ValueObject\ModerationStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A complaint of the public form (G2): rights, removal or correction, with who sent it, the good-faith
 * confirmation and the page it came from, and staff's decision. It is never deleted (D36 erases only the
 * complainant, `eraseComplainant()`); it outlives its venue
 * (`venue_id` SET NULL). The photos it names are `ModerationCasePhoto` rows; while the case is open they are
 * hidden (`Venues\PublicPhotoPolicy`).
 *
 * The id comes from the caller (`EntityId::generate()`), so the command that creates the case can name it as its
 * audit subject.
 */
#[ORM\Entity]
#[ORM\Table(name: 'moderation_cases')]
#[ORM\Index(name: 'idx_moderation_cases_venue_id', columns: ['venue_id'])]
class ModerationCase
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', nullable: true, onDelete: 'SET NULL')]
    private ?Venue $venue;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $venueReference;

    #[ORM\Column(type: Types::STRING, length: 12, enumType: ModerationKind::class)]
    private ModerationKind $kind;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: ModerationStatus::class)]
    private ModerationStatus $status = ModerationStatus::Open;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $complainantName;

    #[ORM\Column(type: Types::STRING, length: 254, nullable: true)]
    private ?string $complainantEmail;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $goodFaithAt;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $sourceUrl;

    #[ORM\Column(type: Types::STRING, length: 10, nullable: true, enumType: ModerationDecision::class)]
    private ?ModerationDecision $decision = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $decisionNote = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $decidedBy = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $tookEntryOffline = false;

    /**
     * When D36's retention erased name, e-mail and message (all three NULL from then on).
     */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $complainantErasedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    /**
     * A complaint about a known venue has no reference; one from the footer names the venue as typed.
     */
    public function __construct(
        string $id,
        ModerationKind $kind,
        ?Venue $venue,
        ?string $venueReference,
        string $complainantName,
        string $complainantEmail,
        string $message,
        ?string $sourceUrl,
        \DateTimeImmutable $at,
    ) {
        $this->id = $id;
        $this->kind = $kind;
        $this->venue = $venue;
        $this->venueReference = null === $venue ? $venueReference : null;
        $this->complainantName = $complainantName;
        $this->complainantEmail = $complainantEmail;
        $this->message = $message;
        $this->sourceUrl = $sourceUrl;
        $this->goodFaithAt = $at;
        $this->createdAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * Staff took the entry offline because of this case; a `keep` decision puts it back online.
     */
    public function recordEntryTakenOffline(\DateTimeImmutable $at): void
    {
        $this->tookEntryOffline = true;
        $this->updatedAt = $at;
    }

    /**
     * Closes the case. The caller checks `isOpen()` first: a decided case is never decided again.
     */
    public function decide(ModerationDecision $decision, ?string $note, string $by, \DateTimeImmutable $at): void
    {
        \assert($this->isOpen());
        $this->status = ModerationStatus::Decided;
        $this->decision = $decision;
        $this->decisionNote = null === $note || '' === trim($note) ? null : $note;
        $this->decidedBy = $by;
        $this->decidedAt = $at;
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

    public function venueReference(): ?string
    {
        return $this->venueReference;
    }

    public function kind(): ModerationKind
    {
        return $this->kind;
    }

    /**
     * D36: three years after the decision the complainant's name, e-mail address and message go; the case, its
     * decision and its photo links stay as evidence. A second call changes nothing.
     */
    public function eraseComplainant(\DateTimeImmutable $at): void
    {
        \assert(!$this->isOpen());

        if (null !== $this->complainantErasedAt) {
            return;
        }

        $this->complainantName = null;
        $this->complainantEmail = null;
        $this->message = null;
        $this->complainantErasedAt = $at;
        $this->updatedAt = $at;
    }

    public function complainantName(): ?string
    {
        return $this->complainantName;
    }

    public function complainantEmail(): ?string
    {
        return $this->complainantEmail;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    public function complainantErasedAt(): ?\DateTimeImmutable
    {
        return $this->complainantErasedAt;
    }

    public function goodFaithAt(): \DateTimeImmutable
    {
        return $this->goodFaithAt;
    }

    public function sourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function decisionNote(): ?string
    {
        return $this->decisionNote;
    }

    public function decidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function decidedBy(): ?string
    {
        return $this->decidedBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isOpen(): bool
    {
        return ModerationStatus::Open === $this->status;
    }

    public function decision(): ?ModerationDecision
    {
        return $this->decision;
    }

    public function tookEntryOffline(): bool
    {
        return $this->tookEntryOffline;
    }
}
