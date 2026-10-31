<?php

declare(strict_types=1);

namespace App\Venues;

use App\Shared\Translation\EnabledLocale;
use App\Venues\Entity\Room;
use App\Venues\Entity\RoomTranslation;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\Seating;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes the fields of the room editor's drawer onto a room (#25, CMS-5, D23), shared by
 * AddRoom and ChangeRoom so both follow one rule set:
 *
 * - texts are trimmed; for each locale given, an empty name removes the locale's row, a
 *   description needs a name in its locale, and the default locale always keeps a name
 *   (VenueRuleViolation::roomNameRequired(), field `names.<locale>`);
 * - the facts and all four seating ranges are replaced (a type left out is not offered);
 * - when anything changed, Room::reshape() moves the room's updated_at, its stale token, also
 *   for a change of texts alone.
 *
 * A locale that is not enabled, an unknown Seating key or FloorMaterial value is an
 * \InvalidArgumentException: the form restricts them, so it is a programming error. Ranges and
 * limits are the form's and the CHECKs' (chk_rooms_*). The content_key is never touched.
 */
final class RoomFields
{
    public function __construct(
        private readonly EnabledLocale $locales,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, string>                      $names        locale → name
     * @param array<string, ?string>                     $descriptions locale → description
     * @param array<string, array{min: ?int, max: ?int}> $seating      Seating value → range
     *
     * @return bool whether anything changed
     *
     * @throws VenueRuleViolation
     */
    public function write(
        Room $room,
        bool $isNew,
        array $names,
        array $descriptions,
        ?int $capacityMin,
        ?int $capacityMax,
        ?int $areaM2,
        ?string $heightM,
        ?string $floorMaterial,
        array $seating,
    ): bool {
        $floor = null === $floorMaterial ? null : (FloorMaterial::tryFrom($floorMaterial) ?? throw new \InvalidArgumentException(\sprintf('"%s" is not a floor material.', $floorMaterial)));
        foreach (array_keys($seating) as $type) {
            if (null === Seating::tryFrom($type)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not a seating type.', $type));
            }
        }

        $existing = [];
        if (!$isNew) {
            foreach ($this->em->getRepository(RoomTranslation::class)->findBy(['room' => $room]) as $translation) {
                $existing[$translation->locale()] = $translation;
            }
        }
        $texts = $this->texts($names, $descriptions, $existing);

        $changed = $isNew
            || $room->capacityMin() !== $capacityMin
            || $room->capacityMax() !== $capacityMax
            || $room->areaM2() !== $areaM2
            || $room->heightM() !== (null === $heightM ? null : (float) $heightM)
            || $room->floorMaterial() !== $floor;
        foreach (Seating::cases() as $type) {
            $range = $seating[$type->value] ?? ['min' => null, 'max' => null];
            $changed = $changed || $room->seatingMin($type) !== $range['min'] || $room->seatingMax($type) !== $range['max'];
        }

        foreach ($texts as $locale => [$name, $description]) {
            $translation = $existing[$locale] ?? null;
            if ('' === $name) {
                if (null !== $translation) {
                    $this->em->remove($translation);
                    $changed = true;
                }
            } elseif (null === $translation) {
                $this->em->persist(new RoomTranslation($room, $locale, $name, $description));
                $changed = true;
            } elseif ($translation->name() !== $name || $translation->description() !== $description) {
                $translation->revise($name, $description);
                $changed = true;
            }
        }
        if (!$changed) {
            return false;
        }

        $room->reshape($capacityMin, $capacityMax, $areaM2, $heightM, $floor);
        foreach (Seating::cases() as $type) {
            $range = $seating[$type->value] ?? ['min' => null, 'max' => null];
            $room->offerSeating($type, $range['min'], $range['max']);
        }

        return true;
    }

    /**
     * The normalised texts per given locale, checked before anything is written.
     *
     * @param array<string, string>          $names
     * @param array<string, ?string>         $descriptions
     * @param array<string, RoomTranslation> $existing
     *
     * @return array<string, array{string, ?string}> locale → [name, description]
     *
     * @throws VenueRuleViolation
     */
    private function texts(array $names, array $descriptions, array $existing): array
    {
        $texts = [];
        foreach (array_unique([...array_keys($names), ...array_keys($descriptions)]) as $locale) {
            $locale = (string) $locale;
            if (!$this->locales->isEnabled($locale)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not an enabled locale.', $locale));
            }
            $name = trim($names[$locale] ?? '');
            $description = trim($descriptions[$locale] ?? '');
            $description = '' === $description ? null : $description;
            if ('' === $name && (null !== $description || $locale === $this->locales->default())) {
                throw VenueRuleViolation::roomNameRequired($locale);
            }
            $texts[$locale] = [$name, $description];
        }

        $default = $this->locales->default();
        if (!isset($texts[$default]) && !isset($existing[$default])) {
            throw VenueRuleViolation::roomNameRequired($default);
        }

        return $texts;
    }
}
