<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Venues\Command\AddRoom;
use App\Venues\Command\ChangeRoom;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\Seating;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The room editor's drawer (#25, CMS-5, D23), submitted as JSON through `RoomType`. The form restricts
 * what the handlers refuse with an `\InvalidArgumentException` (locales, seating types, floor values)
 * and checks the ranges `chk_rooms_*` would refuse; the name rules are the handler's too.
 */
final class RoomData
{
    /** The room's row token; only the change form has it (validation group `token`). */
    #[Assert\NotBlank(message: 'cms.venue.loaded_at.invalid', groups: ['token'])]
    #[Assert\Regex(pattern: '/\A\d{1,12}\z/', message: 'cms.venue.loaded_at.invalid', normalizer: 'mb_scrub', groups: ['token'])]
    public ?string $loadedAt = null;

    /** @var array<string, ?string> locale → name */
    public array $names = [];

    /** @var array<string, ?string> locale → description */
    public array $descriptions = [];

    #[Assert\Range(notInRangeMessage: 'cms.room.capacity.range', min: 1, max: 50000)]
    public ?int $capacityMin = null;

    #[Assert\Range(notInRangeMessage: 'cms.room.capacity.range', min: 1, max: 50000)]
    public ?int $capacityMax = null;

    #[Assert\Range(notInRangeMessage: 'cms.room.area.range', min: 1, max: 50000)]
    public ?int $areaM2 = null;

    /** `numeric(4,2)`, 0 < h ≤ 30 (`chk_rooms_height_m`). */
    #[Assert\Regex(pattern: '/\A\d{1,2}(\.\d{1,2})?\z/', message: 'cms.room.height.format', normalizer: 'mb_scrub')]
    public ?string $heightM = null;

    public ?FloorMaterial $floorMaterial = null;

    /** @var array<string, ?array{min: ?int, max: ?int}> Seating value → range */
    public array $seating = [];

    public function toAddCommand(int $venueNumber): AddRoom
    {
        return new AddRoom($venueNumber, $this->namesArray(), $this->descriptions, $this->capacityMin, $this->capacityMax, $this->areaM2, $this->heightM, $this->floorMaterial?->value, $this->seatingArray());
    }

    public function toChangeCommand(int $venueNumber, string $roomId): ChangeRoom
    {
        return new ChangeRoom($venueNumber, $roomId, (string) $this->loadedAt, $this->namesArray(), $this->descriptions, $this->capacityMin, $this->capacityMax, $this->areaM2, $this->heightM, $this->floorMaterial?->value, $this->seatingArray());
    }

    #[Assert\Callback]
    public function validateRules(ExecutionContextInterface $context): void
    {
        if (null !== $this->capacityMin && null !== $this->capacityMax && $this->capacityMin > $this->capacityMax) {
            $context->buildViolation('cms.room.capacity.max_below_min')->atPath('capacityMax')->addViolation();
        }
        if (null !== $this->heightM && 1 === preg_match('/\A\d{1,2}(\.\d{1,2})?\z/', $this->heightM) && ((float) $this->heightM <= 0.0 || (float) $this->heightM > 30.0)) {
            $context->buildViolation('cms.room.height.range')->atPath('heightM')->addViolation();
        }
        foreach ($this->descriptions as $locale => $description) {
            if (null !== $description && '' !== trim($description) && '' === trim((string) ($this->names[$locale] ?? ''))) {
                $context->buildViolation('cms.room.name.required')->atPath(\sprintf('names[%s]', $locale))->addViolation();
            }
        }
        foreach ($this->seating as $type => $range) {
            if (null !== ($range['min'] ?? null) && null !== ($range['max'] ?? null) && $range['min'] > $range['max']) {
                $context->buildViolation('cms.room.capacity.max_below_min')->atPath(\sprintf('seating[%s][max]', $type))->addViolation();
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function namesArray(): array
    {
        return array_map(static fn (?string $name): string => $name ?? '', $this->names);
    }

    /**
     * @return array<string, array{min: ?int, max: ?int}>
     */
    private function seatingArray(): array
    {
        $seating = [];
        foreach (Seating::cases() as $type) {
            $range = $this->seating[$type->value] ?? null;
            $seating[$type->value] = ['min' => $range['min'] ?? null, 'max' => $range['max'] ?? null];
        }

        return $seating;
    }
}
