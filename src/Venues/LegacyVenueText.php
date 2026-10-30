<?php

declare(strict_types=1);

namespace App\Venues;

/**
 * The rules that turn a venue's Vorstellungstext into the description, the summary and the
 * folder key (#21, D10–D12). Pure, and public for #32, which must use the same rules or the
 * two exports would fight over those paths.
 */
final class LegacyVenueText
{
    public const int SUMMARY_MAX_LENGTH = 200;

    /** A paragraph of this many characters is prose rather than a fact line (rule 3). */
    private const int LONG_PARAGRAPH = 200;

    /** A sentence boundary shorter than this gives no useful summary. */
    private const int SUMMARY_MIN_LENGTH = 60;

    private const string KEY_ID_SEPARATOR = '-';

    /** The section headings of a Vorstellungstext, folded; measured on the owner's file. */
    private const array FACT_LABELS = [
        'nutzung',
        'kapazität für gesetztes essen',
        'kapazität für stehempfang / apéro',
        'kapazität für stehempfang/apéro',
        'kapazität für stehempfang',
        'kapazität',
        'kapazitäten',
        'sprachen die vom personal gesprochen werden',
        'sprachen, die vom personal gesprochen werden',
        'sperrstunde',
        'einrichtung',
        'service',
        'essen / trinken',
        'party möglich?',
        'essen',
        'infrastruktur',
        'zeremonie',
        'zusammenarbeit dienstleister?',
        'zusammenarbeit mit caterer',
    ];

    private const array DESCRIPTION_LABELS = ['beschrieb', 'beschreib', 'beschreibung'];

    /**
     * A sentence end followed by a capital or an opening quote. The lookbehinds keep ordinals
     * and abbreviations from ending a sentence (`16. Jahrhundert`, `St. Gallen`, `(max. 120`).
     */
    private const string SENTENCE_END = '/(?<![0-9])(?<!\bSt)(?<!\bNr)(?<!\bDr)(?<!\bca)(?<!\bmax)(?<!\bmin)(?<!\bbzw)(?<!\binkl)(?<!\bexkl)(?<!\bevtl)(?<!\bggf)(?<!\busw)(?<!\betc)(?<!\bz)(?<!\bu)(?<!\bd)[.!?…]["»”)]?(?=\s+["„«\p{Lu}])/u';

    /**
     * Folds a paragraph for comparing it with a label.
     */
    public static function fold(string $text): string
    {
        $normalized = \Normalizer::normalize($text, \Normalizer::NFC);
        $collapsed = (string) preg_replace('/\s+/u', ' ', false === $normalized ? $text : $normalized);

        return rtrim(trim(mb_strtolower($collapsed)), ' :.');
    }

    /**
     * The venue's description. The first rule that gives text wins:
     * 1. after the first description label, up to the next fact label;
     * 2. before the first fact label, without label paragraphs;
     * 3. every paragraph of at least 200 characters that is not a label;
     * 4. nothing (the page falls back to the summary).
     *
     * @param list<string> $paragraphs
     *
     * @return array{text: ?string, rule: 1|2|3|4}
     */
    public static function description(array $paragraphs): array
    {
        $folded = array_map(self::fold(...), $paragraphs);
        $isFact = static fn (string $paragraph): bool => \in_array($paragraph, self::FACT_LABELS, true);
        $isLabel = static fn (string $paragraph): bool => $isFact($paragraph) || \in_array($paragraph, self::DESCRIPTION_LABELS, true);

        $candidates = [
            1 => self::afterDescriptionLabel($paragraphs, $folded, $isFact),
            2 => self::beforeFirstFact($paragraphs, $folded, $isFact, $isLabel),
            3 => array_filter($paragraphs, static fn (string $paragraph, int $i): bool => !$isLabel($folded[$i]) && mb_strlen($paragraph) >= self::LONG_PARAGRAPH, \ARRAY_FILTER_USE_BOTH),
        ];
        foreach ($candidates as $rule => $found) {
            if ([] !== $found) {
                return ['text' => implode("\n\n", $found), 'rule' => $rule];
            }
        }

        return ['text' => null, 'rule' => 4];
    }

    /**
     * The summary: whole sentences up to 200 characters when that reaches 60, else a word cut
     * with `…`. Without a description it is the venue's Ambiente.
     *
     * @return array{text: string, source: 'whole'|'sentence'|'word'|'ambience'}
     */
    public static function summary(?string $description, string $ambience): array
    {
        if (null === $description) {
            return ['text' => trim($ambience), 'source' => 'ambience'];
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $description));
        if (mb_strlen($text) <= self::SUMMARY_MAX_LENGTH) {
            return ['text' => $text, 'source' => 'whole'];
        }

        $end = null;
        preg_match_all(self::SENTENCE_END, $text, $matches, \PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$match, $offset]) {
            $length = mb_strlen(substr($text, 0, $offset + \strlen($match)));
            if ($length >= self::SUMMARY_MIN_LENGTH && $length <= self::SUMMARY_MAX_LENGTH) {
                $end = $length;
            }
        }
        if (null !== $end) {
            return ['text' => mb_substr($text, 0, $end), 'source' => 'sentence'];
        }

        $head = mb_substr($text, 0, self::SUMMARY_MAX_LENGTH - 1);
        $space = mb_strrpos($head, ' ');

        return ['text' => rtrim(false === $space ? $head : mb_substr($head, 0, $space)).'…', 'source' => 'word'];
    }

    /**
     * `<ascii-name-slug>-<first 8 of the Wix id>`, at most ContentFormat::KEY_MAX_LENGTH. Only
     * for a venue that has no folder yet: a folder is found again by its last 8 characters,
     * so a changed name does not make a new one.
     */
    public static function key(string $name, string $id8): string
    {
        $tail = self::KEY_ID_SEPARATOR.$id8;

        return rtrim(substr(SlugAllocator::base($name), 0, ContentFormat::KEY_MAX_LENGTH - \strlen($tail)), '-').$tail;
    }

    /**
     * @param list<string>           $paragraphs
     * @param list<string>           $folded
     * @param callable(string): bool $isFact
     *
     * @return list<string>
     */
    private static function afterDescriptionLabel(array $paragraphs, array $folded, callable $isFact): array
    {
        $found = [];
        $inside = false;
        foreach ($paragraphs as $i => $paragraph) {
            if (!$inside) {
                $inside = \in_array($folded[$i], self::DESCRIPTION_LABELS, true);
                continue;
            }
            if ($isFact($folded[$i])) {
                break;
            }
            $found[] = $paragraph;
        }

        return $found;
    }

    /**
     * @param list<string>           $paragraphs
     * @param list<string>           $folded
     * @param callable(string): bool $isFact
     * @param callable(string): bool $isLabel
     *
     * @return list<string>
     */
    private static function beforeFirstFact(array $paragraphs, array $folded, callable $isFact, callable $isLabel): array
    {
        $found = [];
        foreach ($paragraphs as $i => $paragraph) {
            if ($isFact($folded[$i])) {
                break;
            }
            if (!$isLabel($folded[$i])) {
                $found[] = $paragraph;
            }
        }

        return $found;
    }
}
