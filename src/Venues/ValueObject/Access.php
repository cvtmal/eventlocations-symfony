<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Erreichbarkeit (FI-4): near a station, a motorway or an airport. Derived from the
 * venue's distances, never stored; the search cycle (#6) sets the distance thresholds.
 */
enum Access: string implements TranslatableInterface
{
    case Station = 'station';
    case Highway = 'highway';
    case Airport = 'airport';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('access.'.$this->value, [], 'venues', $locale);
    }
}
