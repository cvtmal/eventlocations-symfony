<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The seating types a room states capacities for (DP-8). Each case has a min/max column
 * pair on `rooms` (`capacity_<value>_min`, `capacity_<value>_max`); see Room::seatingMin().
 */
enum Seating: string implements TranslatableInterface
{
    case Seminar = 'seminar';
    case Banquet = 'banquet';
    case Apero = 'apero';
    case Concert = 'concert';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('seating.'.$this->value, [], 'venues', $locale);
    }
}
