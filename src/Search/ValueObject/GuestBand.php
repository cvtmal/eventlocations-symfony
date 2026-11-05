<?php

declare(strict_types=1);

namespace App\Search\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The four bands of the listing's guests filter (#51), on capacity_max. The value is the
 * canonical `guests` parameter and also a guest count, so a legacy count buckets into its band.
 */
enum GuestBand: int implements TranslatableInterface
{
    case UpTo50 = 50;
    case UpTo100 = 100;
    case UpTo150 = 150;
    case Over150 = 151;

    /**
     * Callers pass a count of at least 1.
     */
    public static function fromGuests(int $guests): self
    {
        return match (true) {
            $guests <= 50 => self::UpTo50,
            $guests <= 100 => self::UpTo100,
            $guests <= 150 => self::UpTo150,
            default => self::Over150,
        };
    }

    public function min(): int
    {
        return match ($this) {
            self::UpTo50 => 1,
            self::UpTo100 => 51,
            self::UpTo150 => 101,
            self::Over150 => 151,
        };
    }

    public function max(): ?int
    {
        return match ($this) {
            self::UpTo50 => 50,
            self::UpTo100 => 100,
            self::UpTo150 => 150,
            self::Over150 => null,
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('filter.guests_band.'.$this->value, [], 'search', $locale);
    }
}
