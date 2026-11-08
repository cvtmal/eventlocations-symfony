<?php

declare(strict_types=1);

namespace App\Portal\Entity;

use App\Shared\Uid\EntityId;
use App\Venues\Entity\Subscription;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The marker of one reminder mail (G9): the term and how many days before its end (`DAYS`). Written once the mail
 * went out, so the second run of `app:subscriptions:remind` sends nothing; `uk_…_days_before` is the backstop.
 */
#[ORM\Entity]
#[ORM\Table(name: 'portal_subscription_reminders')]
#[ORM\Index(name: 'idx_portal_subscription_reminders_subscription_id', columns: ['subscription_id'])]
#[ORM\UniqueConstraint(name: 'uk_portal_subscription_reminders_subscription_id_days_before', columns: ['subscription_id', 'days_before'])]
class SubscriptionReminder
{
    /** The reminders before a term's end, latest first: `chk_portal_subscription_reminders_days_before`. */
    public const array DAYS = [30, 7];

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Subscription::class)]
    #[ORM\JoinColumn(name: 'subscription_id', nullable: false, onDelete: 'CASCADE')]
    private Subscription $subscription;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $daysBefore;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $sentAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Subscription $subscription, int $daysBefore, \DateTimeImmutable $sentAt)
    {
        if (!\in_array($daysBefore, self::DAYS, true)) {
            throw new \InvalidArgumentException(\sprintf('A reminder goes out %s days before the end, not %d.', implode(' or ', self::DAYS), $daysBefore));
        }
        $this->id = EntityId::generate();
        $this->subscription = $subscription;
        $this->daysBefore = $daysBefore;
        $this->sentAt = $sentAt;
        $this->createdAt = $sentAt;
        $this->updatedAt = $sentAt;
    }

    public function id(): string
    {
        \assert(null !== $this->id);

        return $this->id;
    }

    public function subscription(): Subscription
    {
        return $this->subscription;
    }

    public function daysBefore(): int
    {
        return $this->daysBefore;
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
