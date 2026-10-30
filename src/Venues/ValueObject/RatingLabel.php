<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The word shown next to a venue's rating score (DP-6). Derived from the score, never
 * stored: below 7.7 there is no label.
 */
enum RatingLabel: string implements TranslatableInterface
{
    case Recommended = 'recommended';
    case Excellent = 'excellent';
    case Outstanding = 'outstanding';

    /**
     * Compares on the one-decimal value the database stores (NUMERIC(3,1)), so a float such
     * as 7.6999 cannot fall on the wrong side of a threshold.
     */
    public static function fromScore(float|string $score): ?self
    {
        $tenths = (int) round((float) $score * 10);

        return match (true) {
            $tenths >= 90 => self::Outstanding,
            $tenths >= 80 => self::Excellent,
            $tenths >= 77 => self::Recommended,
            default => null,
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('rating_label.'.$this->value, [], 'venues', $locale);
    }
}
