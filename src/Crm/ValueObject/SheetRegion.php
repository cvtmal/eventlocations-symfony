<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * The `State/Region` cell of the customer sheet as the cantons it may mean (#48 R3), so two rows of one
 * name, or a row and a venue, can be told apart when their places differ (D4). The sheet names a canton
 * in German, or a region wider than one: "Basel", "Appenzell", "Zentralschweiz", "Ostschweiz".
 *
 * Liechtenstein maps to `li`, the code of the Liechtenstein venues since #46: a Liechtenstein row fits those
 * venues and other Liechtenstein rows, and no Swiss canton. An empty or unrecognised cell is unknown, and
 * unknown fits everything.
 */
final readonly class SheetRegion
{
    private const array CENTRAL = ['lu', 'ur', 'sz', 'ow', 'nw', 'zg'];
    private const array EAST = ['sg', 'tg', 'ar', 'ai', 'gl', 'sh', 'gr'];

    /** By the folded text (`fold()`): the German name, its common spellings and the cantons' own names. */
    private const array CODES = [
        'zurich' => ['zh'], 'zuerich' => ['zh'],
        'bern' => ['be'], 'berne' => ['be'],
        'luzern' => ['lu'],
        'uri' => ['ur'],
        'schwyz' => ['sz'],
        'obwalden' => ['ow'],
        'nidwalden' => ['nw'],
        'glarus' => ['gl'],
        'zug' => ['zg'],
        'freiburg' => ['fr'], 'fribourg' => ['fr'],
        'solothurn' => ['so'],
        'basel stadt' => ['bs'],
        'basel landschaft' => ['bl'], 'basel land' => ['bl'], 'baselland' => ['bl'], 'baselbiet' => ['bl'],
        'schaffhausen' => ['sh'],
        'appenzell ausserrhoden' => ['ar'],
        'appenzell innerrhoden' => ['ai'],
        'st gallen' => ['sg'], 'sankt gallen' => ['sg'],
        'graubunden' => ['gr'], 'graubuenden' => ['gr'],
        'aargau' => ['ag'],
        'thurgau' => ['tg'],
        'tessin' => ['ti'], 'ticino' => ['ti'],
        'waadt' => ['vd'], 'vaud' => ['vd'],
        'wallis' => ['vs'], 'valais' => ['vs'],
        'neuenburg' => ['ne'], 'neuchatel' => ['ne'],
        'genf' => ['ge'], 'geneve' => ['ge'],
        'jura' => ['ju'],
        'basel' => ['bs', 'bl'],
        'appenzell' => ['ai', 'ar'],
        'zentralschweiz' => self::CENTRAL, 'innerschweiz' => self::CENTRAL,
        'ostschweiz' => self::EAST,
        'liechtenstein' => ['li'],
    ];

    /**
     * @param list<string>|null $codes null when unknown
     */
    private function __construct(
        private ?string $label,
        private ?array $codes,
    ) {
    }

    public static function fromSheet(string $text): self
    {
        $label = SheetText::clean($text);
        if ('' === $label) {
            return new self(null, null);
        }

        return new self($label, self::CODES[self::fold($label)] ?? null);
    }

    /** A cell named a region this class knows. */
    public function isKnown(): bool
    {
        return null !== $this->codes;
    }

    /** The cleaned cell, as the sheet writes it ("Zürich"), or null when the cell is empty. */
    public function label(): ?string
    {
        return $this->label;
    }

    /** A filled cell that names no region this class knows: the importer warns about it. */
    public function isUnrecognised(): bool
    {
        return null !== $this->label && null === $this->codes;
    }

    /**
     * @return list<string> the lower-case canton codes it may mean; empty when unknown
     */
    public function codes(): array
    {
        return $this->codes ?? [];
    }

    /** A canton beats a wider region, and any region beats an unknown one. */
    public function isMoreSpecificThan(self $other): bool
    {
        if (null === $this->codes) {
            return false;
        }

        return null === $other->codes || \count($this->codes) < \count($other->codes);
    }

    /**
     * @return bool|null null when either side is unknown
     */
    public function compatibleWithCanton(?string $cantonCode): ?bool
    {
        if (null === $this->codes || null === $cantonCode || '' === $cantonCode) {
            return null;
        }

        return \in_array(strtolower($cantonCode), $this->codes, true);
    }

    /** The code sets overlap, or either side is unknown. */
    public function compatibleWith(self $other): bool
    {
        if (null === $this->codes || null === $other->codes) {
            return true;
        }

        return [] !== array_intersect($this->codes, $other->codes);
    }

    /** Lower case, marks dropped ("Graubünden" → "graubunden"), anything but a letter or digit as one space. */
    private static function fold(string $text): string
    {
        $text = \Normalizer::normalize(mb_strtolower($text), \Normalizer::FORM_D);
        \assert(\is_string($text));
        $text = (string) preg_replace('/\p{Mn}+/u', '', $text);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
    }
}
