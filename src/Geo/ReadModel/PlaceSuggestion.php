<?php

declare(strict_types=1);

namespace App\Geo\ReadModel;

use App\Geo\ValueObject\PlaceType;

/**
 * One Ort/PLZ autocomplete entry. A canton carries only its code (the label comes from the
 * `geo` catalogue via App\Geo\ValueObject\Canton); a town carries its id, official name and
 * the ZIP to show (the matched one for a ZIP search, else the town's lowest).
 */
final readonly class PlaceSuggestion
{
    private function __construct(
        public PlaceType $type,
        public string $cantonCode,
        public ?string $townId,
        public ?string $name,
        public ?string $zip,
    ) {
    }

    public static function canton(string $code): self
    {
        return new self(PlaceType::Canton, $code, null, null, null);
    }

    public static function town(string $id, string $name, string $cantonCode, string $zip): self
    {
        return new self(PlaceType::Town, $cantonCode, $id, $name, $zip);
    }
}
