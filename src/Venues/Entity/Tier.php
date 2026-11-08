<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Shared\Uid\EntityId;
use App\Venues\Repository\TierRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A listing plan (F12, D14/D15): a row of limits and flags, so what a venue may show is data. Free and Pro are
 * seeded by the migration; one more row needs no code. The venue points at its tier (`venues.tier_id`, default
 * Free); read the limits that apply to a venue through `EntitlementResolver`, never from here.
 *
 * - `price_rappen` is CHF a year as shown, final (D24, D25), invoiced by hand (D2). `vat_line` adds "zzgl. MwSt."
 *   after the price wherever it is shown; off by default (the operator is not registered for VAT).
 * - `rank_weight`: higher lists first within a relevance bucket; 0 means no boost and no "Top-Eintrag" label.
 * - `*_max` limits are ceilings; `event_types_max` NULL means every event type.
 * - `code` is stable and never edited. The code names only the fallbacks (`KnownTiers`).
 */
#[ORM\Entity(repositoryClass: TierRepository::class)]
#[ORM\Table(name: 'tiers')]
#[ORM\UniqueConstraint(name: 'uk_tiers_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uk_tiers_name', columns: ['name'])]
class Tier
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\Column(type: Types::STRING, length: 30)]
    private string $code;

    #[ORM\Column(type: Types::STRING, length: 60)]
    private string $name;

    #[ORM\Column(type: Types::INTEGER)]
    private int $priceRappen;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $vatLine;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $rankWeight;

    #[ORM\Column(type: Types::INTEGER)]
    private int $photosMax;

    #[ORM\Column(type: Types::INTEGER)]
    private int $roomsMax;

    #[ORM\Column(type: Types::INTEGER)]
    private int $videosMax;

    #[ORM\Column(type: Types::INTEGER)]
    private int $documentsMax;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $contactVisible;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $lastMinuteAllowed;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $detailedReport;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $eventTypesMax;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $code,
        string $name,
        int $priceRappen,
        bool $vatLine,
        int $rankWeight,
        int $photosMax,
        int $roomsMax,
        int $videosMax,
        int $documentsMax,
        bool $contactVisible,
        bool $lastMinuteAllowed,
        bool $detailedReport,
        ?int $eventTypesMax,
    ) {
        $this->id = EntityId::generate();
        $this->code = $code;
        $this->name = $name;
        $this->priceRappen = $priceRappen;
        $this->vatLine = $vatLine;
        $this->rankWeight = $rankWeight;
        $this->photosMax = $photosMax;
        $this->roomsMax = $roomsMax;
        $this->videosMax = $videosMax;
        $this->documentsMax = $documentsMax;
        $this->contactVisible = $contactVisible;
        $this->lastMinuteAllowed = $lastMinuteAllowed;
        $this->detailedReport = $detailedReport;
        $this->eventTypesMax = $eventTypesMax;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * The CMS edit: everything but the code.
     */
    public function revise(
        string $name,
        int $priceRappen,
        bool $vatLine,
        int $rankWeight,
        int $photosMax,
        int $roomsMax,
        int $videosMax,
        int $documentsMax,
        bool $contactVisible,
        bool $lastMinuteAllowed,
        bool $detailedReport,
        ?int $eventTypesMax,
    ): void {
        $this->name = $name;
        $this->priceRappen = $priceRappen;
        $this->vatLine = $vatLine;
        $this->rankWeight = $rankWeight;
        $this->photosMax = $photosMax;
        $this->roomsMax = $roomsMax;
        $this->videosMax = $videosMax;
        $this->documentsMax = $documentsMax;
        $this->contactVisible = $contactVisible;
        $this->lastMinuteAllowed = $lastMinuteAllowed;
        $this->detailedReport = $detailedReport;
        $this->eventTypesMax = $eventTypesMax;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        \assert(null !== $this->id);

        return $this->id;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function priceRappen(): int
    {
        return $this->priceRappen;
    }

    public function vatLine(): bool
    {
        return $this->vatLine;
    }

    public function rankWeight(): int
    {
        return $this->rankWeight;
    }

    public function photosMax(): int
    {
        return $this->photosMax;
    }

    public function roomsMax(): int
    {
        return $this->roomsMax;
    }

    public function videosMax(): int
    {
        return $this->videosMax;
    }

    public function documentsMax(): int
    {
        return $this->documentsMax;
    }

    public function contactVisible(): bool
    {
        return $this->contactVisible;
    }

    public function lastMinuteAllowed(): bool
    {
        return $this->lastMinuteAllowed;
    }

    public function detailedReport(): bool
    {
        return $this->detailedReport;
    }

    public function eventTypesMax(): ?int
    {
        return $this->eventTypesMax;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
