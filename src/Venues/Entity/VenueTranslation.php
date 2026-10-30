<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The venue-authored text of a venue in one locale, including its slug (S4/S5: one slug
 * per locale, unique within the locale). Every venue has a `de` row; read models join it
 * through App\Shared\Translation\TranslationJoin. The write side (#9) validates the locale
 * with EnabledLocale::isEnabled().
 */
#[ORM\Entity]
#[ORM\Table(name: 'venue_translations')]
#[ORM\Index(name: 'idx_venue_translations_venue_id', columns: ['venue_id'])]
#[ORM\UniqueConstraint(name: 'uk_venue_translations_venue_id_locale', columns: ['venue_id', 'locale'])]
#[ORM\UniqueConstraint(name: 'uk_venue_translations_locale_slug', columns: ['locale', 'slug'])]
class VenueTranslation
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', nullable: false, onDelete: 'CASCADE')]
    private Venue $venue;

    #[ORM\Column(type: Types::STRING, length: 2)]
    private string $locale;

    #[ORM\Column(type: Types::STRING, length: 160)]
    private string $slug;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $summary;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    #[ORM\Column(type: Types::STRING, length: 160, nullable: true)]
    private ?string $metaDescription;

    #[ORM\Column(type: Types::STRING, length: 250, nullable: true)]
    private ?string $parkingText;

    #[ORM\Column(type: Types::STRING, length: 60, nullable: true)]
    private ?string $ambience = null;

    #[ORM\Column(type: Types::STRING, length: 250, nullable: true)]
    private ?string $capacityNote = null;

    #[ORM\Column(type: Types::STRING, length: 250, nullable: true)]
    private ?string $partyNote = null;

    #[ORM\Column(type: Types::STRING, length: 250, nullable: true)]
    private ?string $openingHours = null;

    #[ORM\Column(type: Types::STRING, length: 60, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Venue $venue,
        string $locale,
        string $slug,
        string $summary,
        ?string $description = null,
        ?string $metaDescription = null,
        ?string $parkingText = null,
    ) {
        $this->id = EntityId::generate();
        $this->venue = $venue;
        $this->locale = $locale;
        $this->slug = $slug;
        $this->summary = $summary;
        $this->description = $description;
        $this->metaDescription = $metaDescription;
        $this->parkingText = $parkingText;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * Replaces the slug and the texts (the import updates the row in place, #9).
     */
    public function revise(string $slug, string $summary, ?string $description, ?string $metaDescription, ?string $parkingText): void
    {
        $this->slug = $slug;
        $this->summary = $summary;
        $this->description = $description;
        $this->metaDescription = $metaDescription;
        $this->parkingText = $parkingText;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * A slug chosen by hand in the CMS (#24); VenueSlugs::change() checks it and keeps the
     * history.
     */
    public function changeSlug(string $slug): void
    {
        $this->slug = $slug;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * The owner's wedding texts (#31, S14), kept verbatim; NULL means unknown.
     */
    public function describeWedding(?string $ambience, ?string $capacityNote, ?string $partyNote, ?string $openingHours): void
    {
        $this->ambience = $ambience;
        $this->capacityNote = $capacityNote;
        $this->partyNote = $partyNote;
        $this->openingHours = $openingHours;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Where the venue is when it has no canton (#21), verbatim; NULL otherwise.
     */
    public function changeRegion(?string $region): void
    {
        $this->region = $region;
        $this->updatedAt = new \DateTimeImmutable();
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

    public function slug(): string
    {
        return $this->slug;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function metaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function parkingText(): ?string
    {
        return $this->parkingText;
    }

    public function ambience(): ?string
    {
        return $this->ambience;
    }

    public function capacityNote(): ?string
    {
        return $this->capacityNote;
    }

    public function partyNote(): ?string
    {
        return $this->partyNote;
    }

    public function openingHours(): ?string
    {
        return $this->openingHours;
    }

    public function region(): ?string
    {
        return $this->region;
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
