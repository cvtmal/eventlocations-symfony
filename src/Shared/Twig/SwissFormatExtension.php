<?php

declare(strict_types=1);

namespace App\Shared\Twig;

use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Formats dates and numbers the Swiss way in the current language. The request locale
 * stays a language code because catalogues are per language; the region is added only
 * here, so every language keeps Swiss formats (1'234'567.5 in German). Dates are shown
 * in Zurich time while storage stays UTC.
 *
 * Catalogues receive numbers pre-formatted by these functions, never through ICU
 * `{n, number}`: the translator locale is `de`, not `de_CH`, so ICU would group with `.`.
 */
final readonly class SwissFormatExtension
{
    private const TIMEZONE = 'Europe/Zurich';
    private const REGION = 'CH';

    public function __construct(
        private LocaleSwitcher $localeSwitcher,
    ) {
    }

    #[AsTwigFilter('swiss_date')]
    public function date(\DateTimeInterface $value): string
    {
        return $this->formatDate($value, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE);
    }

    #[AsTwigFilter('swiss_number')]
    public function number(int|float $value, ?int $decimals = null): string
    {
        $formatter = new \NumberFormatter($this->locale(), \NumberFormatter::DECIMAL);
        if (null !== $decimals) {
            $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
        }
        $formatted = $formatter->format($value);
        if (false === $formatted) {
            throw new \UnexpectedValueException(intl_get_error_message());
        }

        return $formatted;
    }

    /**
     * A capacity or price range, "50 - 450" (ASCII space-hyphen-space, as on the original site).
     */
    #[AsTwigFunction('swiss_range')]
    public function range(int $min, int $max): string
    {
        if ($min === $max) {
            return $this->number($min);
        }

        return $this->number($min).' - '.$this->number($max);
    }

    private function formatDate(\DateTimeInterface $value, int $dateType, int $timeType): string
    {
        $formatted = (new \IntlDateFormatter($this->locale(), $dateType, $timeType, self::TIMEZONE))->format($value);
        if (false === $formatted) {
            throw new \UnexpectedValueException(intl_get_error_message());
        }

        return $formatted;
    }

    private function locale(): string
    {
        return $this->localeSwitcher->getLocale().'_'.self::REGION;
    }
}
