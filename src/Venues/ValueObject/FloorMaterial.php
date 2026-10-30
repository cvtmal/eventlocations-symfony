<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The floor of a room (DP-8 "Boden"). Stored in the nullable `rooms.floor_material`
 * (chk_rooms_floor_material).
 */
enum FloorMaterial: string implements TranslatableInterface
{
    case Various = 'various';
    case Parquet = 'parquet';
    case Carpet = 'carpet';
    case Stone = 'stone';
    case Wood = 'wood';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('floor_material.'.$this->value, [], 'venues', $locale);
    }
}
