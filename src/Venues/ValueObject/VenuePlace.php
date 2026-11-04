<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

use App\Geo\ValueObject\Canton;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Where a venue is, as far as that is known (#21): a town, else a canton, else the source's
 * region text, else nothing. Translating it gives the one line a card or the detail head
 * shows: `Stäfa`, `Kanton Zürich`, the text verbatim, or ''. "Kanton Zürich" and not a bare
 * "Zürich", which would read as the town and state something the source does not say; but a
 * plain "Liechtenstein", a country and no canton (#46, the select on `canton_code`).
 */
final readonly class VenuePlace implements TranslatableInterface
{
    public function __construct(
        public ?string $townName,
        public ?Canton $canton,
        public ?string $region,
    ) {
    }

    public static function of(?string $townName, ?string $cantonCode, ?string $region): self
    {
        return new self($townName, null === $cantonCode ? null : Canton::from($cantonCode), $region);
    }

    public function isKnown(): bool
    {
        return null !== $this->townName || null !== $this->canton || null !== $this->region;
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        if (null !== $this->townName) {
            return $this->townName;
        }
        if (null !== $this->canton) {
            return $translator->trans('place.canton', ['canton' => $this->canton->trans($translator, $locale), 'canton_code' => $this->canton->value], 'venues', $locale);
        }

        return $this->region ?? '';
    }
}
