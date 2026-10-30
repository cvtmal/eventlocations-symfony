<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * The CMS address field "PLZ / Ort" (#24, D1): a four-digit ZIP, whitespace, the town name,
 * e.g. "8706 Meilen". The form rule and RelocateVenueHandler both parse with it, so they
 * agree on what a valid value is.
 */
final readonly class TownText
{
    private function __construct(
        public string $zip,
        public string $name,
    ) {
    }

    /**
     * Null when the text is not "<4 digits> <name>"; the name is trimmed and its inner runs of
     * whitespace collapse to one space.
     */
    public static function parse(string $text): ?self
    {
        if (1 !== preg_match('/\A\s*(\d{4})\s+(\S.*?)\s*\z/su', $text, $match)) {
            return null;
        }

        return new self($match[1], (string) preg_replace('/\s+/u', ' ', $match[2]));
    }

    public static function format(string $zip, string $name): string
    {
        return $zip.' '.$name;
    }

    public function __toString(): string
    {
        return self::format($this->zip, $this->name);
    }
}
