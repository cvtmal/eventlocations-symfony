<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\VenueEdit;
use App\Geo\ValueObject\Canton;
use App\Venues\Command\RelocateVenue;
use App\Venues\ValueObject\TownText;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Adresse (D1–D4, D17, L1). "PLZ / Ort" is one text resolved by `RelocateVenueHandler`, which also owns the
 * rules that need the database (unknown or ambiguous town, a town without coordinates). The form checks the
 * shapes and the CHECKs: lat and lng together, a street needs a town, a distance name needs its km.
 *
 * `useTownCentre` is the "Ortsmitte übernehmen" press, set by `VenueAddressType` from the button; it is no
 * field, so `ChangedFields` skips it. Regions are present only for a venue with neither town nor canton.
 */
final class VenueAddressData implements VenueCardData
{
    use CarriesLoadedAt;

    private const string DECIMAL = '/\A\d{1,3}(\.\d+)?\z/';
    private const string KM = '/\A\d{1,3}(\.\d)?\z/';

    #[Assert\Length(max: 100, maxMessage: 'cms.venue.too_long')]
    public ?string $town = null;

    public ?Canton $canton = null;

    #[Assert\Length(max: 150, maxMessage: 'cms.venue.too_long')]
    public ?string $street = null;

    #[Assert\Sequentially(constraints: [
        new Assert\Regex(pattern: self::DECIMAL, message: 'cms.venue.decimal.format', normalizer: 'mb_scrub'),
        new Assert\Range(notInRangeMessage: 'cms.venue.lat.range', min: 45.8, max: 47.9),
    ])]
    public ?string $lat = null;

    #[Assert\Sequentially(constraints: [
        new Assert\Regex(pattern: self::DECIMAL, message: 'cms.venue.decimal.format', normalizer: 'mb_scrub'),
        new Assert\Range(notInRangeMessage: 'cms.venue.lng.range', min: 5.9, max: 10.6),
    ])]
    public ?string $lng = null;

    #[Assert\Sequentially(constraints: [
        new Assert\Regex(pattern: self::KM, message: 'cms.venue.km.format', normalizer: 'mb_scrub'),
        new Assert\Range(notInRangeMessage: 'cms.venue.km.range', min: 0, max: 500),
    ])]
    public ?string $stationKm = null;

    #[Assert\Length(max: 150, maxMessage: 'cms.venue.too_long')]
    public ?string $stationName = null;

    #[Assert\Sequentially(constraints: [
        new Assert\Regex(pattern: self::KM, message: 'cms.venue.km.format', normalizer: 'mb_scrub'),
        new Assert\Range(notInRangeMessage: 'cms.venue.km.range', min: 0, max: 500),
    ])]
    public ?string $highwayKm = null;

    #[Assert\Length(max: 150, maxMessage: 'cms.venue.too_long')]
    public ?string $highwayName = null;

    #[Assert\Sequentially(constraints: [
        new Assert\Regex(pattern: self::KM, message: 'cms.venue.km.format', normalizer: 'mb_scrub'),
        new Assert\Range(notInRangeMessage: 'cms.venue.km.range', min: 0, max: 500),
    ])]
    public ?string $airportKm = null;

    #[Assert\Length(max: 150, maxMessage: 'cms.venue.too_long')]
    public ?string $airportName = null;

    /** @var array<string, ?string> locale → region text, only for a venue with neither town nor canton */
    public array $regions = [];

    public bool $useTownCentre = false;

    public static function from(VenueEdit $edit): static
    {
        $data = new self();
        $data->loadedAt = $edit->loadedAt;
        $data->town = $edit->town;
        $data->canton = null === $edit->cantonCode ? null : Canton::tryFrom($edit->cantonCode);
        $data->street = $edit->header->street;
        $data->lat = $edit->lat;
        $data->lng = $edit->lng;
        $data->stationKm = $edit->stationKm;
        $data->stationName = $edit->stationName;
        $data->highwayKm = $edit->highwayKm;
        $data->highwayName = $edit->highwayName;
        $data->airportKm = $edit->airportKm;
        $data->airportName = $edit->airportName;
        if (self::showsRegions($edit)) {
            foreach ($edit->translations as $locale => $translation) {
                $data->regions[$locale] = $translation->region;
            }
        }

        return $data;
    }

    /**
     * The region text is for a venue without a canton (#21); once a town or canton exists it is cleared.
     */
    public static function showsRegions(VenueEdit $edit): bool
    {
        return null === $edit->town && null === $edit->cantonCode;
    }

    public function toCommand(int $venueNumber, VenueEdit $edit, array $changedFields): RelocateVenue
    {
        $placed = null !== $this->town || null !== $this->canton;
        $hadRegion = [] !== array_filter($edit->translations, static fn ($translation): bool => null !== $translation->region);

        return new RelocateVenue(
            $venueNumber,
            $this->token(),
            $changedFields,
            $this->town,
            $this->canton?->value,
            $this->street,
            null === $this->lat ? null : (float) $this->lat,
            null === $this->lng ? null : (float) $this->lng,
            $this->stationKm,
            $this->stationName,
            $this->highwayKm,
            $this->highwayName,
            $this->airportKm,
            $this->airportName,
            $this->regions,
            $this->useTownCentre,
            $placed && $hadRegion,
        );
    }

    #[Assert\Callback]
    public function validateRules(ExecutionContextInterface $context): void
    {
        if (null !== $this->town && mb_check_encoding($this->town, 'UTF-8') && null === TownText::parse($this->town)) {
            $context->buildViolation('cms.venue.town.format')->atPath('town')->addViolation();
        }
        if (null !== $this->street && null === $this->town) {
            $context->buildViolation('cms.venue.street.needs_town')->atPath('street')->addViolation();
        }
        if ((null === $this->lat) !== (null === $this->lng)) {
            $context->buildViolation('cms.venue.coordinates.both')->atPath(null === $this->lat ? 'lat' : 'lng')->addViolation();
        }
        $distances = [
            'stationKm' => [$this->stationKm, $this->stationName],
            'highwayKm' => [$this->highwayKm, $this->highwayName],
            'airportKm' => [$this->airportKm, $this->airportName],
        ];
        foreach ($distances as $path => [$km, $name]) {
            if (null !== $name && null === $km) {
                $context->buildViolation('cms.venue.km.needs_km')->atPath($path)->addViolation();
            }
        }
    }
}
