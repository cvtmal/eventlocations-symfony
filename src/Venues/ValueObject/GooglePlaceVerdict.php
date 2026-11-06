<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The place ID lookup's decision on one venue (#57): a reason and what its sentence quotes. `words` and `area`
 * are Google's or our words, `km` the distance to our pin, `numbers` the other venues sharing the place. The
 * sentence (`google_place.reason.<code>` in `venues`) goes into the report only, never to the console.
 */
final readonly class GooglePlaceVerdict implements TranslatableInterface
{
    /**
     * @param list<string> $words
     * @param list<int>    $numbers
     */
    public function __construct(
        public GooglePlaceReason $reason,
        public array $words = [],
        public string $area = '',
        public ?float $km = null,
        public array $numbers = [],
    ) {
    }

    public static function match(): self
    {
        return new self(GooglePlaceReason::Match);
    }

    /**
     * @param list<string> $words
     */
    public static function doubtful(GooglePlaceReason $reason, array $words = [], string $area = '', ?float $km = null): self
    {
        return new self($reason, $words, $area, $km);
    }

    public static function failed(): self
    {
        return new self(GooglePlaceReason::Failed);
    }

    public static function notFound(): self
    {
        return new self(GooglePlaceReason::NoResult);
    }

    /**
     * @param list<int> $numbers the other venues the same place fits
     */
    public static function samePlace(array $numbers): self
    {
        return new self(GooglePlaceReason::SamePlace, numbers: $numbers);
    }

    public function confident(): bool
    {
        return GooglePlaceReason::Match === $this->reason;
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('google_place.reason.'.$this->reason->value, [
            'words' => implode(', ', $this->words),
            'area' => $this->area,
            'km' => null === $this->km ? '' : number_format($this->km, 1, '.', ''),
            'numbers' => implode(', ', $this->numbers),
        ], 'venues', $locale);
    }
}
