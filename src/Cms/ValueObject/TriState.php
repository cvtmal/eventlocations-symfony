<?php

declare(strict_types=1);

namespace App\Cms\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A nullable boolean as radio values (P6): a `ChoiceType` with `true`/`false`/`null` choices numbers its
 * values `0`/`1`/`2`, which a request cannot be read by, so the cards use these strings.
 */
enum TriState: string implements TranslatableInterface
{
    case Yes = 'yes';
    case No = 'no';
    case Unknown = 'unknown';

    public static function fromBool(?bool $value): self
    {
        return match ($value) {
            true => self::Yes,
            false => self::No,
            null => self::Unknown,
        };
    }

    public function toBool(): ?bool
    {
        return match ($this) {
            self::Yes => true,
            self::No => false,
            self::Unknown => null,
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('tri_state.'.$this->value, [], 'cms', $locale);
    }
}
