<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Shared\Uid\EntityId;
use App\Venues\ValueObject\ReviewerRole;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A guest review shown on the detail page (DP-6). `locale` is the language the text is
 * written in (a review is not translated). Only the month is kept: the constructor
 * normalises the date to the first day (chk_venue_reviews_reviewed_month). Reviews are
 * listed by month, newest first.
 */
#[ORM\Entity]
#[ORM\Table(name: 'venue_reviews')]
#[ORM\Index(name: 'idx_venue_reviews_venue_id', columns: ['venue_id'])]
class VenueReview
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', nullable: false, onDelete: 'CASCADE')]
    private Venue $venue;

    #[ORM\Column(type: Types::STRING, length: 2)]
    private string $locale;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $firstName;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: ReviewerRole::class)]
    private ReviewerRole $role;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $reviewedMonth;

    #[ORM\Column(type: Types::STRING, length: 1000)]
    private string $text;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Venue $venue,
        string $locale,
        string $firstName,
        ReviewerRole $role,
        \DateTimeImmutable $reviewedMonth,
        string $text,
    ) {
        $this->id = EntityId::generate();
        $this->venue = $venue;
        $this->locale = $locale;
        $this->firstName = $firstName;
        $this->role = $role;
        $this->reviewedMonth = $reviewedMonth->setDate((int) $reviewedMonth->format('Y'), (int) $reviewedMonth->format('n'), 1)->setTime(0, 0);
        $this->text = $text;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
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

    public function locale(): string
    {
        return $this->locale;
    }

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function role(): ReviewerRole
    {
        return $this->role;
    }

    public function reviewedMonth(): \DateTimeImmutable
    {
        return $this->reviewedMonth;
    }

    public function text(): string
    {
        return $this->text;
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
