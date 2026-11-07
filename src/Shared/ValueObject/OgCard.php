<?php

declare(strict_types=1);

namespace App\Shared\ValueObject;

/**
 * The text of one Open Graph card (1200 × 630, `templates/brand/og_card.svg.twig`, docs/brand.md). SVG does not wrap text, so the
 * card breaks its title here, by characters: Hanken Grotesk Bold at the card's 64 px averages about 34 px a character, and the text
 * column is 760 px wide. A title longer than its lines ends in "…", so a long venue name never runs into the mark.
 *
 * Three shapes: the default card (the tagline, every page without a photo), a venue card (F7b: a venue without a photo) and a
 * landing card (F8: canton, town and event-type pages).
 */
final readonly class OgCard
{
    public const int WIDTH = 1200;
    public const int HEIGHT = 630;

    private const int TITLE_WIDTH = 22;
    private const int LINE_WIDTH = 44;

    /**
     * @param list<string> $title one entry per line
     */
    private function __construct(
        public string $variant,
        public string $eyebrow,
        public array $title,
        public string $facts,
    ) {
    }

    public static function default(string $tagline): self
    {
        return new self('default', '', self::wrap($tagline, self::TITLE_WIDTH + 4, 3), '');
    }

    /**
     * @param string $place e.g. "Reinach BL"
     * @param string $facts e.g. "20–120 Personen · Seminar, Apéro"
     */
    public static function venue(string $name, string $place, string $facts): self
    {
        return new self('venue', self::line($place), self::wrap($name, self::TITLE_WIDTH, 2), self::line($facts));
    }

    /**
     * @param string $eyebrow e.g. "Eventlokale"
     * @param string $heading e.g. "Seminarräume im Kanton Zürich"
     * @param string $facts   e.g. "48 Locations"
     */
    public static function landing(string $eyebrow, string $heading, string $facts): self
    {
        return new self('landing', self::line($eyebrow), self::wrap($heading, self::TITLE_WIDTH, 2), self::line($facts));
    }

    /**
     * Greedy word wrap by characters; a word longer than a line is cut, and text beyond the last line ends in "…".
     *
     * @return list<string>
     */
    public static function wrap(string $text, int $width, int $lines): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $result = [];
        $current = '';
        foreach ($words as $word) {
            while (mb_strlen($word) > $width) {
                if ('' !== $current) {
                    $result[] = $current;
                    $current = '';
                }
                $result[] = mb_substr($word, 0, $width);
                $word = mb_substr($word, $width);
            }
            $candidate = '' === $current ? $word : $current.' '.$word;
            if (mb_strlen($candidate) <= $width) {
                $current = $candidate;
            } else {
                $result[] = $current;
                $current = $word;
            }
        }
        if ('' !== $current) {
            $result[] = $current;
        }

        if (\count($result) <= $lines) {
            return $result;
        }

        $kept = \array_slice($result, 0, $lines);
        $last = (string) array_pop($kept);
        $kept[] = rtrim(mb_substr($last, 0, $width - 1), ' ,.-–·').'…';

        return $kept;
    }

    private static function line(string $text): string
    {
        return self::wrap($text, self::LINE_WIDTH, 1)[0] ?? '';
    }
}
