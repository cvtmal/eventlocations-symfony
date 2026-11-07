<?php

declare(strict_types=1);

namespace App\Venues\Entity;

use App\Geo\Entity\Canton;
use App\Geo\Entity\Town;
use App\Shared\Uid\EntityId;
use App\Venues\Repository\VenueRepository;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A venue, the root of the Venues aggregate. Its owned rows (translations, terms, rooms,
 * documents, photos) point at it and are deleted with it.
 *
 * - `number` is the stable public number (Merkliste id, S7), an identity column the
 *   database assigns on insert; Doctrine reads it back after the flush.
 * - `tier_id` is the venue's listing plan (F12). Ask `EntitlementResolver` what it allows; the listings join `tiers`
 *   for the rank weight.
 * - A venue may have no address at all (#21). With a town, the canton is always the town's:
 *   the town wins in the constructor and in relocate(), so canton_code cannot drift (not
 *   enforced in the DB). Without one, the canton is set only when the source names it.
 * - Decimal columns (distances, rating score, room height) take PHP strings such as '8.4'
 *   and are read back as floats.
 * - The slug and all venue-authored text live in VenueTranslation; the name is a proper
 *   noun and stays here.
 * - The search indexes idx_venues_earth (GiST on ll_to_earth(lat, lng)) and
 *   idx_venues_search_key (GIN trigram on geo_search_key(name)) are expression indexes that
 *   exist in the database only and are deliberately not mapped here (as on Town).
 * - `content_key` is the content folder's name (#9, S8). A venue with a key is written by
 *   `app:venues:import` and by the CMS (#24). `content_hash` is the hash of the content it
 *   was last imported from (the import skips an unchanged venue); NULL after a prune so a
 *   reappearing folder is applied again. Demo venues have neither.
 * - `cms_edited_at` is set by every CMS save (markCmsEdited(), through VenueEditing) and
 *   cleared when an import applies (recordContentHash()). While it is set, the import skips
 *   the venue unless told to overwrite CMS edits (S8), so staff work is never lost silently.
 *   A prune leaves it as it is: it applies no content.
 * - `coordinates_from_town` marks coordinates copied from the town centre ("Ortsmitte
 *   übernehmen" in the CMS), not the venue's own point: no map pin or geo data may claim
 *   them. Only with coordinates (chk_venues_coordinates_from_town); the import writes false.
 * - `awaits_first_publish` (Q20) is set by CreateVenue only, the CMS's and the portal's way to
 *   a new venue, and cleared by every publish(), an import's included. An imported or seeded
 *   venue never has it, so only a venue created here can fire NewVenuePublished, and once.
 * - The event facts (#31, S14; the party and curfew facts left with event-vocabulary, F3) are
 *   all nullable, NULL meaning unknown, and the page shows nothing for them. `capacity_seated`
 *   / `capacity_standing` are the owner's numbers and are not checked against `capacity_max`.
 *   Their translated texts live in VenueTranslation.
 * - `google_place_id` (#53) is the only Google value we may store (Maps Platform Terms §3.2.3,
 *   Places API policies): Google's own element draws the rating in the browser. It is outside
 *   the content format, so no import writes it. `google_rating_hidden` is staff's switch to hide
 *   that rating; it exists only with a place ID (chk_venues_google_rating_hidden).
 */
#[ORM\Entity(repositoryClass: VenueRepository::class)]
#[ORM\Table(name: 'venues')]
#[ORM\Index(name: 'idx_venues_town_id', columns: ['town_id'])]
#[ORM\Index(name: 'idx_venues_canton_code', columns: ['canton_code'])]
#[ORM\Index(name: 'idx_venues_tier_id', columns: ['tier_id'])]
#[ORM\UniqueConstraint(name: 'uk_venues_number', columns: ['number'])]
#[ORM\UniqueConstraint(name: 'uk_venues_content_key', columns: ['content_key'])]
class Venue
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private ?string $id = null;

    #[ORM\Column(type: Types::INTEGER, insertable: false, updatable: false, generated: 'INSERT')]
    private ?int $number = null;

    #[ORM\Column(type: Types::STRING, length: 150)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: Tier::class)]
    #[ORM\JoinColumn(name: 'tier_id', nullable: false, onDelete: 'RESTRICT', options: ['default' => KnownTiers::FREE_ID])]
    private Tier $tier;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: VenueStatus::class)]
    private VenueStatus $status;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $capacityMin;

    #[ORM\Column(type: Types::INTEGER)]
    private int $capacityMax;

    #[ORM\Column(type: Types::STRING, length: 150, nullable: true)]
    private ?string $street;

    #[ORM\Column(type: Types::STRING, length: 4, nullable: true)]
    private ?string $zip;

    #[ORM\ManyToOne(targetEntity: Town::class)]
    #[ORM\JoinColumn(name: 'town_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Town $town;

    #[ORM\ManyToOne(targetEntity: Canton::class)]
    #[ORM\JoinColumn(name: 'canton_code', referencedColumnName: 'code', nullable: true, onDelete: 'RESTRICT')]
    private ?Canton $canton;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $lat;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $lng;

    #[ORM\Column(type: Types::DECIMAL, precision: 4, scale: 1, nullable: true)]
    private ?string $distanceStationKm = null;

    #[ORM\Column(type: Types::STRING, length: 150, nullable: true)]
    private ?string $stationName = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 4, scale: 1, nullable: true)]
    private ?string $distanceHighwayKm = null;

    #[ORM\Column(type: Types::STRING, length: 150, nullable: true)]
    private ?string $highwayName = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 4, scale: 1, nullable: true)]
    private ?string $distanceAirportKm = null;

    #[ORM\Column(type: Types::STRING, length: 150, nullable: true)]
    private ?string $airportName = null;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: Types::STRING, length: 254, nullable: true)]
    private ?string $inquiryEmail = null;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(type: Types::STRING, length: 150, nullable: true)]
    private ?string $contactPerson = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $daylight = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true, enumType: PriceLevel::class)]
    private ?PriceLevel $priceLevel = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $capacitySeated = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $capacityStanding = null;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $instagram = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $roomCount = null;

    #[ORM\Column(name: 'largest_room_m2', type: Types::INTEGER, nullable: true)]
    private ?int $largestRoomM2 = null;

    #[ORM\Column(name: 'max_room_height_m', type: Types::DECIMAL, precision: 4, scale: 2, nullable: true)]
    private ?string $maxRoomHeightM = null;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $contentKey;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $contentHash = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cmsEditedAt = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $coordinatesFromTown = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $awaitsFirstPublish = false;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $googlePlaceId = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $googleRatingHidden = false;

    /** G12: when staff confirmed the facts; null = unchecked, so the entry is not indexed (VenueIndexGate). */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $factsCheckedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $name,
        Tier $tier,
        VenueStatus $status,
        int $capacityMax,
        ?int $capacityMin,
        ?Town $town,
        ?string $zip,
        ?string $street,
        ?float $lat,
        ?float $lng,
        ?string $contentKey = null,
        ?Canton $canton = null,
    ) {
        $this->id = EntityId::generate();
        $this->name = $name;
        $this->tier = $tier;
        $this->status = $status;
        $this->capacityMax = $capacityMax;
        $this->capacityMin = $capacityMin;
        $this->town = $town;
        $this->canton = $town?->canton() ?? $canton;
        $this->zip = $zip;
        $this->street = $street;
        $this->lat = $lat;
        $this->lng = $lng;
        $this->contentKey = $contentKey;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * Changes the name; the slug is the write side's business (VenueTranslation, history).
     */
    public function rename(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    /**
     * Moves the venue to another address; the canton follows the town, and $canton counts
     * only without one. $coordinatesFromTown says the coordinates are the town centre, not the
     * venue's own point; it needs coordinates.
     */
    public function relocate(
        ?Town $town,
        ?string $zip,
        ?string $street,
        ?float $lat,
        ?float $lng,
        ?Canton $canton = null,
        bool $coordinatesFromTown = false,
    ): void {
        \assert(!$coordinatesFromTown || null !== $lat, 'Town-centre coordinates need coordinates.');

        $this->town = $town;
        $this->canton = $town?->canton() ?? $canton;
        $this->zip = $zip;
        $this->street = $street;
        $this->lat = $lat;
        $this->lng = $lng;
        $this->coordinatesFromTown = $coordinatesFromTown;
        $this->touch();
    }

    public function changeTier(Tier $tier): void
    {
        $this->tier = $tier;
        $this->touch();
    }

    public function publish(): void
    {
        $this->status = VenueStatus::Published;
        $this->awaitsFirstPublish = false;
        $this->touch();
    }

    /**
     * Called by CreateVenue only: this venue's first publish is the one that fires NewVenuePublished.
     */
    public function markAwaitingFirstPublish(): void
    {
        $this->awaitsFirstPublish = true;
    }

    public function awaitsFirstPublish(): bool
    {
        return $this->awaitsFirstPublish;
    }

    public function unpublish(): void
    {
        $this->status = VenueStatus::Unpublished;
        $this->touch();
    }

    public function changeCapacity(int $max, ?int $min): void
    {
        $this->capacityMax = $max;
        $this->capacityMin = $min;
        $this->touch();
    }

    public function changeContact(?string $phone, ?string $inquiryEmail, ?string $website, ?string $contactPerson): void
    {
        $this->phone = $phone;
        $this->inquiryEmail = $inquiryEmail;
        $this->website = $website;
        $this->contactPerson = $contactPerson;
        $this->touch();
    }

    /**
     * Distances in km as decimal strings ('2.5'); a name is kept only with its distance
     * (chk_venues_distance_names).
     */
    public function changeDistances(
        ?string $stationKm,
        ?string $stationName,
        ?string $highwayKm,
        ?string $highwayName,
        ?string $airportKm,
        ?string $airportName,
    ): void {
        $this->distanceStationKm = $stationKm;
        $this->stationName = $stationName;
        $this->distanceHighwayKm = $highwayKm;
        $this->highwayName = $highwayName;
        $this->distanceAirportKm = $airportKm;
        $this->airportName = $airportName;
        $this->touch();
    }

    /**
     * FAQ answers (DP-9); NULL means unknown. The parking answer is translated text on
     * VenueTranslation.
     */
    public function answerFaq(?bool $daylight): void
    {
        $this->daylight = $daylight;
        $this->touch();
    }

    public function changePriceLevel(?PriceLevel $priceLevel): void
    {
        $this->priceLevel = $priceLevel;
        $this->touch();
    }

    /**
     * The owner's seated and standing capacity (S14), beside the capacity range of
     * changeCapacity().
     */
    public function changeSeatingCapacity(?int $seated, ?int $standing): void
    {
        $this->capacitySeated = $seated;
        $this->capacityStanding = $standing;
        $this->touch();
    }

    public function changeInstagram(?string $instagram): void
    {
        $this->instagram = $instagram;
        $this->touch();
    }

    /**
     * Sets the Google place ID and the switch that hides its rating (#53). Without a place ID the
     * switch is cleared, because it has nothing to hide (chk_venues_google_rating_hidden).
     *
     * @return bool whether anything changed
     */
    public function changeGooglePlace(?string $placeId, bool $ratingHidden): bool
    {
        if (null === $placeId) {
            $ratingHidden = false;
        }
        if ($placeId === $this->googlePlaceId && $ratingHidden === $this->googleRatingHidden) {
            return false;
        }

        $this->googlePlaceId = $placeId;
        $this->googleRatingHidden = $ratingHidden;
        $this->touch();

        return true;
    }

    /**
     * The room facts the filters read (FI-4): number of rooms, the largest room's area and
     * the tallest room's height ('4.20'). Set by the write side from the venue's rooms.
     */
    public function summariseRooms(?int $roomCount, ?int $largestRoomM2, ?string $maxRoomHeightM): void
    {
        $this->roomCount = $roomCount;
        $this->largestRoomM2 = $largestRoomM2;
        $this->maxRoomHeightM = $maxRoomHeightM;
        $this->touch();
    }

    /**
     * Records the hash of the content this venue was imported from; only content venues
     * have one (chk_venues_content_hash).
     */
    public function recordContentHash(string $hash): void
    {
        \assert(null !== $this->contentKey, 'Only a venue with a content key has a content hash.');

        $this->contentHash = $hash;
        $this->cmsEditedAt = null;
        $this->touch();
    }

    /**
     * G12: staff confirm (true) or withdraw (false) that the facts are checked. False when nothing changes, so a
     * double click writes nothing; a new confirmation keeps the first date.
     */
    public function confirmFacts(bool $checked, \DateTimeImmutable $at): bool
    {
        if ($checked === (null !== $this->factsCheckedAt)) {
            return false;
        }
        $this->factsCheckedAt = $checked ? $at : null;
        $this->touch();

        return true;
    }

    public function factsCheckedAt(): ?\DateTimeImmutable
    {
        return $this->factsCheckedAt;
    }

    /**
     * Records a CMS save (#24, S8): the import skips this venue until an import applies or is
     * told to overwrite. Called by VenueEditing::close() only, never by the import.
     */
    public function markCmsEdited(\DateTimeImmutable $at): void
    {
        $this->cmsEditedAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * Forgets the content hash, so the next import applies the content again.
     */
    public function forgetContentHash(): void
    {
        $this->contentHash = null;
        $this->touch();
    }

    public function id(): string
    {
        \assert(null !== $this->id);

        return $this->id;
    }

    /**
     * Assigned by the database on insert, so only available after the first flush.
     */
    public function number(): int
    {
        \assert(null !== $this->number);

        return $this->number;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function tier(): Tier
    {
        return $this->tier;
    }

    public function status(): VenueStatus
    {
        return $this->status;
    }

    public function isPublished(): bool
    {
        return VenueStatus::Published === $this->status;
    }

    public function capacityMin(): ?int
    {
        return $this->capacityMin;
    }

    public function capacityMax(): int
    {
        return $this->capacityMax;
    }

    public function street(): ?string
    {
        return $this->street;
    }

    public function zip(): ?string
    {
        return $this->zip;
    }

    public function town(): ?Town
    {
        return $this->town;
    }

    public function canton(): ?Canton
    {
        return $this->canton;
    }

    public function lat(): ?float
    {
        return $this->lat;
    }

    public function lng(): ?float
    {
        return $this->lng;
    }

    public function distanceStationKm(): ?float
    {
        return self::toFloat($this->distanceStationKm);
    }

    public function stationName(): ?string
    {
        return $this->stationName;
    }

    public function distanceHighwayKm(): ?float
    {
        return self::toFloat($this->distanceHighwayKm);
    }

    public function highwayName(): ?string
    {
        return $this->highwayName;
    }

    public function distanceAirportKm(): ?float
    {
        return self::toFloat($this->distanceAirportKm);
    }

    public function airportName(): ?string
    {
        return $this->airportName;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function inquiryEmail(): ?string
    {
        return $this->inquiryEmail;
    }

    public function website(): ?string
    {
        return $this->website;
    }

    public function contactPerson(): ?string
    {
        return $this->contactPerson;
    }

    public function daylight(): ?bool
    {
        return $this->daylight;
    }

    public function priceLevel(): ?PriceLevel
    {
        return $this->priceLevel;
    }

    public function capacitySeated(): ?int
    {
        return $this->capacitySeated;
    }

    public function capacityStanding(): ?int
    {
        return $this->capacityStanding;
    }

    public function instagram(): ?string
    {
        return $this->instagram;
    }

    public function googlePlaceId(): ?string
    {
        return $this->googlePlaceId;
    }

    public function googleRatingHidden(): bool
    {
        return $this->googleRatingHidden;
    }

    public function roomCount(): ?int
    {
        return $this->roomCount;
    }

    public function largestRoomM2(): ?int
    {
        return $this->largestRoomM2;
    }

    public function maxRoomHeightM(): ?float
    {
        return self::toFloat($this->maxRoomHeightM);
    }

    public function contentKey(): ?string
    {
        return $this->contentKey;
    }

    public function contentHash(): ?string
    {
        return $this->contentHash;
    }

    public function cmsEditedAt(): ?\DateTimeImmutable
    {
        return $this->cmsEditedAt;
    }

    public function coordinatesFromTown(): bool
    {
        return $this->coordinatesFromTown;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    private static function toFloat(?string $decimal): ?float
    {
        return null === $decimal ? null : (float) $decimal;
    }
}
