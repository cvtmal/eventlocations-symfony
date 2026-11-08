<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Venues\Command\ReviseTier;
use App\Venues\ReadModel\TierDefinition;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The form of the screen "Tarife" (F12): everything of a tier but its code. The price is Rappen (`FrancsTextType`
 * turns the typed francs into them); `eventTypesMax` null means every event type. The limits are ceilings staff may
 * set from 0, so a tier can switch a thing off; 1000 is far above anything a venue holds, and refuses a typo.
 */
final class TierData
{
    /** CHF 100'000 a year. */
    public const int MAX_PRICE_RAPPEN = 10_000_000;
    public const int MAX_RANK_WEIGHT = 100;
    public const int MAX_COUNT = 1000;

    #[Assert\NotBlank(message: 'cms.tier.name.required')]
    #[Assert\Length(max: 60, maxMessage: 'cms.tier.too_long')]
    public ?string $name = null;

    #[Assert\NotNull(message: 'cms.tier.price.required')]
    #[Assert\Range(notInRangeMessage: 'cms.tier.price.range', min: 0, max: self::MAX_PRICE_RAPPEN)]
    public ?int $priceRappen = null;

    public bool $vatLine = false;

    #[Assert\NotNull(message: 'cms.tier.number.required')]
    #[Assert\Range(notInRangeMessage: 'cms.tier.range', min: 0, max: self::MAX_RANK_WEIGHT)]
    public ?int $rankWeight = null;

    #[Assert\NotNull(message: 'cms.tier.number.required')]
    #[Assert\Range(notInRangeMessage: 'cms.tier.range', min: 0, max: self::MAX_COUNT)]
    public ?int $photosMax = null;

    #[Assert\NotNull(message: 'cms.tier.number.required')]
    #[Assert\Range(notInRangeMessage: 'cms.tier.range', min: 0, max: self::MAX_COUNT)]
    public ?int $roomsMax = null;

    #[Assert\NotNull(message: 'cms.tier.number.required')]
    #[Assert\Range(notInRangeMessage: 'cms.tier.range', min: 0, max: self::MAX_COUNT)]
    public ?int $videosMax = null;

    #[Assert\NotNull(message: 'cms.tier.number.required')]
    #[Assert\Range(notInRangeMessage: 'cms.tier.range', min: 0, max: self::MAX_COUNT)]
    public ?int $documentsMax = null;

    public bool $contactVisible = false;

    public bool $lastMinuteAllowed = false;

    public bool $detailedReport = false;

    #[Assert\Range(notInRangeMessage: 'cms.tier.range', min: 1, max: self::MAX_COUNT)]
    public ?int $eventTypesMax = null;

    public static function from(TierDefinition $tier): self
    {
        $data = new self();
        $data->name = $tier->name;
        $data->priceRappen = $tier->priceRappen;
        $data->vatLine = $tier->vatLine;
        $data->rankWeight = $tier->rankWeight;
        $data->photosMax = $tier->photosMax;
        $data->roomsMax = $tier->roomsMax;
        $data->videosMax = $tier->videosMax;
        $data->documentsMax = $tier->documentsMax;
        $data->contactVisible = $tier->contactVisible;
        $data->lastMinuteAllowed = $tier->lastMinuteAllowed;
        $data->detailedReport = $tier->detailedReport;
        $data->eventTypesMax = $tier->eventTypesMax;

        return $data;
    }

    /**
     * @param list<string> $changedFields `ChangedFields::between()` of the loaded data and this
     */
    public function toCommand(string $code, array $changedFields): ReviseTier
    {
        $incomplete = static fn (): \LogicException => new \LogicException('Validate the form before building its command.');

        return new ReviseTier(
            $code,
            (string) $this->name,
            $this->priceRappen ?? throw $incomplete(),
            $this->vatLine,
            $this->rankWeight ?? throw $incomplete(),
            $this->photosMax ?? throw $incomplete(),
            $this->roomsMax ?? throw $incomplete(),
            $this->videosMax ?? throw $incomplete(),
            $this->documentsMax ?? throw $incomplete(),
            $this->contactVisible,
            $this->lastMinuteAllowed,
            $this->detailedReport,
            $this->eventTypesMax,
            $changedFields,
        );
    }
}
