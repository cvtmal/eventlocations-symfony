<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

use App\Venues\ValueObject\PriceLevel;

/**
 * Every card's values of the venue editor, read in one statement with `loadedAt`, so the stale token and
 * the values a form starts from belong together.
 *
 * - `loadedAt` is `EXTRACT(EPOCH FROM updated_at)::bigint` as a string, read from the database because
 *   `updated_at` is `TIMESTAMP(0)` and rounds what PHP set (Probe 7); `VenueEditing::open()` compares it.
 * - Decimals and coordinates are strings as Postgres prints them (`'0.9'`, `'47.3769'`), so an idle save
 *   sends back exactly what was loaded (P5).
 * - `town` is "8706 Meilen" (`TownText::format()`), null without a town. `cantonCode` is the stored one,
 *   which is the town's when there is a town.
 * - `coordinatesFromTown` (L1): the coordinates are the town centre ("Ortsmitte übernehmen"), so the public
 *   page shows no map; `townCentreHint()` names the town for the editor's hint.
 * - `terms` has a key for every `TermGroup` (codes in `position` order, `[]` when none); `mainTerms` holds
 *   the main code of a group that has one (building, setting).
 * - `translations` is keyed by locale and holds only the rows that exist.
 * - `googlePlaceId` and `googleRatingHidden` (#53) are the card "Google-Bewertung"; the switch is only true with an ID.
 */
final readonly class VenueEdit
{
    public const string MISSING_SUMMARY = 'summary';
    public const string MISSING_MAIN_BUILDING = 'main_building';
    public const string MISSING_PHOTO = 'photo';
    public const string MISSING_FACTS = 'facts';
    public const string MISSING_DESCRIPTION = 'description';

    /**
     * @param array<string, VenueEditTranslation> $translations
     * @param array<string, list<string>>         $terms
     * @param array<string, string>               $mainTerms
     */
    public function __construct(
        public VenueHeader $header,
        public string $loadedAt,
        public ?int $capacityMin,
        public int $capacityMax,
        public ?string $town,
        public ?string $cantonCode,
        public ?string $lat,
        public ?string $lng,
        public bool $coordinatesFromTown,
        public ?string $stationKm,
        public ?string $stationName,
        public ?string $highwayKm,
        public ?string $highwayName,
        public ?string $airportKm,
        public ?string $airportName,
        public ?string $phone,
        public ?string $inquiryEmail,
        public bool $acceptsMultiInquiries,
        public ?string $website,
        public ?string $contactPerson,
        public ?string $instagram,
        public ?bool $daylight,
        public ?PriceLevel $priceLevel,
        public ?int $capacitySeated,
        public ?int $capacityStanding,
        public array $translations,
        public array $terms,
        public array $mainTerms,
        public int $galleryPhotoCount,
        public ?string $googlePlaceId,
        public bool $googleRatingHidden,
        /** G12: when staff confirmed the facts, null = unchecked. */
        public ?\DateTimeImmutable $factsCheckedAt = null,
    ) {
    }

    /**
     * What keeps the entry out of the index (G12, `Venues\VenueIndexGate`), in the aside card's order: `facts` (not
     * checked by staff) and `description` (no default-locale description). Empty: a published entry is indexed.
     *
     * @return list<string>
     */
    public function missingForIndex(string $defaultLocale): array
    {
        $missing = [];
        if (null === $this->factsCheckedAt) {
            $missing[] = self::MISSING_FACTS;
        }
        // The ASCII whitespace of the SQL gate's `[[:space:]]`, so the card and VenueIndexGate agree.
        if (1 !== preg_match('/[^ \t\n\v\f\r]/', $this->translation($defaultLocale)->description ?? '')) {
            $missing[] = self::MISSING_DESCRIPTION;
        }

        return $missing;
    }

    public function number(): int
    {
        return $this->header->number;
    }

    public function translation(string $locale): ?VenueEditTranslation
    {
        return $this->translations[$locale] ?? null;
    }

    /**
     * The town of the editor's hint under the coordinates, null when they were typed.
     */
    public function townCentreHint(): ?string
    {
        return $this->coordinatesFromTown ? $this->header->place->townName : null;
    }

    /**
     * What `PublishVenue` would refuse, as its codes and in its order: a summary in the default locale
     * (`btrim` strips spaces only, so does this), a main building, a gallery photo (a room's does not count).
     *
     * @return list<string>
     */
    public function missingForPublish(string $defaultLocale): array
    {
        $missing = [];
        if ('' === trim($this->translation($defaultLocale)->summary ?? '', ' ')) {
            $missing[] = self::MISSING_SUMMARY;
        }
        if (!isset($this->mainTerms['building'])) {
            $missing[] = self::MISSING_MAIN_BUILDING;
        }
        if (0 === $this->galleryPhotoCount) {
            $missing[] = self::MISSING_PHOTO;
        }

        return $missing;
    }
}
