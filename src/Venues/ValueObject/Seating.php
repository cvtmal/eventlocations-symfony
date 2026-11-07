<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The layouts a room states capacities for (DP-8, F4): Bankett, Seminar/Parlament, Apéro/Stehempfang,
 * Konzert/Reihen, U-Form, Block. Each case has a min/max column pair on `rooms` (`capacity_<value>_min`,
 * `capacity_<value>_max`); see Room::seatingMin(). A new case needs a migration with the pair, its CHECK and
 * a new chk_rooms_capacity_layouts.
 */
enum Seating: string implements TranslatableInterface
{
    case Seminar = 'seminar';
    case Banquet = 'banquet';
    case Apero = 'apero';
    case Concert = 'concert';
    case UShape = 'u_shape';
    case Block = 'block';

    /**
     * The guest range the layouts give a room: [least minimum, greatest maximum] over the layouts that
     * state a maximum, or null when none does.
     *
     * @param iterable<array{min: ?int, max: ?int}> $ranges
     *
     * @return array{min: ?int, max: int}|null
     */
    public static function guestRange(iterable $ranges): ?array
    {
        $min = null;
        $max = null;
        foreach ($ranges as $range) {
            if (null === $range['max']) {
                continue;
            }
            $max = max($max ?? $range['max'], $range['max']);
            if (null !== $range['min']) {
                $min = min($min ?? $range['min'], $range['min']);
            }
        }

        return null === $max ? null : ['min' => $min, 'max' => $max];
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('seating.'.$this->value, [], 'venues', $locale);
    }
}
