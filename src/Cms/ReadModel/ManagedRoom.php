<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

/**
 * One room in the room editor (#25, CMS-5, D23): every field the drawer edits, the number of
 * photos it holds (they move to the gallery when it is deleted, D14) and its row token.
 */
final readonly class ManagedRoom
{
    /**
     * @param array<string, string>                      $names         locale → name, only the locales with a row
     * @param array<string, ?string>                     $descriptions  locale → description, the same locales
     * @param ?string                                    $heightM       the decimal as the database prints it (`3.50`)
     * @param ?string                                    $floorMaterial a FloorMaterial value
     * @param array<string, array{min: ?int, max: ?int}> $seating       every Seating value → its range
     * @param bool                                       $fromContent   the room came from the content import (it has a content_key)
     * @param string                                     $token         the row token (`RowTokens::sql()` of `rooms.updated_at`)
     */
    public function __construct(
        public string $id,
        public int $position,
        public array $names,
        public array $descriptions,
        public ?int $capacityMin,
        public ?int $capacityMax,
        public ?int $areaM2,
        public ?string $heightM,
        public ?string $floorMaterial,
        public array $seating,
        public bool $fromContent,
        public int $photoCount,
        public string $token,
    ) {
    }

    /**
     * The island's props and the JSON answers of the room endpoints (PF10).
     *
     * @return array{id: string, position: int, names: array<string, string>, descriptions: array<string, ?string>, capacityMin: ?int, capacityMax: ?int, areaM2: ?int, heightM: ?string, floorMaterial: ?string, seating: array<string, array{min: ?int, max: ?int}>, fromContent: bool, photoCount: int, token: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'names' => $this->names,
            'descriptions' => $this->descriptions,
            'capacityMin' => $this->capacityMin,
            'capacityMax' => $this->capacityMax,
            'areaM2' => $this->areaM2,
            'heightM' => $this->heightM,
            'floorMaterial' => $this->floorMaterial,
            'seating' => $this->seating,
            'fromContent' => $this->fromContent,
            'photoCount' => $this->photoCount,
            'token' => $this->token,
        ];
    }
}
