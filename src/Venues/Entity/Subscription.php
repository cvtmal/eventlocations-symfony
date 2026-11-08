<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Shared\Uid\EntityId;
use App\Venues\ValueObject\SubscriptionStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One paid term of a venue (G9, D14): `startsOn` to `endsOn`, both days included, in Europe/Zurich calendar days.
 * `EntitlementResolver` gives the venue the term's tier on every day the term covers and Free on every other day;
 * a lapsed term never takes the venue offline. A renewal is the next row, starting the day after this one ends.
 *
 * `cancelRequestedAt` is the manager's "do not invoice the next term" (the link of the reminder mail): the term
 * still runs to its end. `status` turns `Expired` once the nightly job has handled the end.
 *
 * `amountRappen` is what was paid for the term. A term granted free of charge (0, a pilot) is a normal term with expiry
 * and reminders, but it needs a `note` saying why and is no payment: no `pro_paid`, no «Zahlende Locations» (D18).
 */
#[ORM\Entity]
#[ORM\Table(name: 'subscriptions')]
#[ORM\Index(name: 'idx_subscriptions_venue_id', columns: ['venue_id'])]
#[ORM\Index(name: 'idx_subscriptions_tier_id', columns: ['tier_id'])]
#[ORM\Index(name: 'idx_subscriptions_ends_on', columns: ['ends_on'])]
#[ORM\UniqueConstraint(name: 'uk_subscriptions_venue_id_starts_on', columns: ['venue_id', 'starts_on'])]
class Subscription
{
    public const int INVOICE_REF_MAX = 60;
    public const int NOTE_MAX = 200;
    /** CHF 99'999.99: a year of a listing, invoiced by hand; anything above is a typo. */
    public const int AMOUNT_MAX = 9_999_999;

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', nullable: false, onDelete: 'CASCADE')]
    private Venue $venue;

    #[ORM\ManyToOne(targetEntity: Tier::class)]
    #[ORM\JoinColumn(name: 'tier_id', nullable: false, onDelete: 'RESTRICT')]
    private Tier $tier;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startsOn;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endsOn;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: SubscriptionStatus::class, options: ['default' => 'active'])]
    private SubscriptionStatus $status = SubscriptionStatus::Active;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $paidAt;

    #[ORM\Column(type: Types::INTEGER)]
    private int $amountRappen;

    #[ORM\Column(type: Types::STRING, length: self::INVOICE_REF_MAX, nullable: true)]
    private ?string $invoiceRef;

    #[ORM\Column(type: Types::STRING, length: self::NOTE_MAX, nullable: true)]
    private ?string $note;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelRequestedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Venue $venue, Tier $tier, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn, int $amountRappen, ?string $invoiceRef, ?string $note, \DateTimeImmutable $paidAt)
    {
        if ($endsOn < $startsOn) {
            throw new \InvalidArgumentException('A term cannot end before it starts.');
        }
        if ($amountRappen < 0 || $amountRappen > self::AMOUNT_MAX) {
            throw new \InvalidArgumentException('A term costs between nothing and AMOUNT_MAX.');
        }
        if (null !== $note && mb_strlen($note) > self::NOTE_MAX) {
            throw new \InvalidArgumentException('A note holds at most NOTE_MAX characters.');
        }
        if (0 === $amountRappen && (null === $note || '' === trim($note))) {
            throw new \InvalidArgumentException('A term free of charge needs a note.');
        }
        $this->id = EntityId::generate();
        $this->venue = $venue;
        $this->tier = $tier;
        $this->startsOn = $startsOn->setTime(0, 0);
        $this->endsOn = $endsOn->setTime(0, 0);
        $this->amountRappen = $amountRappen;
        $this->invoiceRef = $invoiceRef;
        $this->note = $note;
        $this->paidAt = $paidAt;
        $this->createdAt = $paidAt;
        $this->updatedAt = $paidAt;
    }

    /**
     * @return bool false when the cancellation was already recorded
     */
    public function requestCancellation(\DateTimeImmutable $at): bool
    {
        if (null !== $this->cancelRequestedAt) {
            return false;
        }
        $this->cancelRequestedAt = $at;
        $this->updatedAt = $at;

        return true;
    }

    public function expire(\DateTimeImmutable $at): void
    {
        $this->status = SubscriptionStatus::Expired;
        $this->updatedAt = $at;
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

    public function tier(): Tier
    {
        return $this->tier;
    }

    public function startsOn(): \DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function endsOn(): \DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function status(): SubscriptionStatus
    {
        return $this->status;
    }

    public function paidAt(): \DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function amountRappen(): int
    {
        return $this->amountRappen;
    }

    /**
     * A pilot or other term granted without payment: it counts nowhere as a paying venue (D18).
     */
    public function isFreeOfCharge(): bool
    {
        return 0 === $this->amountRappen;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function invoiceRef(): ?string
    {
        return $this->invoiceRef;
    }

    public function cancelRequestedAt(): ?\DateTimeImmutable
    {
        return $this->cancelRequestedAt;
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
