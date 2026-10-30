<?php

declare(strict_types=1);

namespace App\Geo\ValueObject;

use App\Geo\Exception\InvalidTownDataset;

/**
 * The official towns dataset (data/geo/towns.csv, see data/geo/SOURCE.md), parsed and validated.
 */
final readonly class TownDataset
{
    private const HEADER = 'zip,name,canton_code,bfs_number,lat,lng,population';

    /**
     * @param list<TownRecord> $records
     */
    private function __construct(private array $records)
    {
    }

    public static function fromCsvFile(string $path): self
    {
        $csv = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if (false === $csv) {
            throw InvalidTownDataset::unreadable($path);
        }

        return self::fromCsvString($csv);
    }

    public static function fromCsvString(string $csv): self
    {
        if (str_starts_with($csv, "\u{FEFF}")) {
            $csv = substr($csv, 3);
        }

        $lines = array_map(static fn (string $line): string => rtrim($line, "\r"), explode("\n", $csv));
        if ('' === end($lines)) {
            array_pop($lines);
        }

        $header = array_shift($lines) ?? '';
        if (self::HEADER !== $header) {
            throw InvalidTownDataset::unexpectedHeader($header);
        }

        $records = [];
        foreach ($lines as $index => $line) {
            $records[] = self::parseRow($line, $index + 2);
        }

        return new self($records);
    }

    /**
     * @return list<TownRecord>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * One town per (canton, name): the lowest ZIP supplies every field, all ZIPs are kept.
     * Sorted by canton (BFS order), then name.
     */
    public function collapsed(): self
    {
        /** @var array<string, list<TownRecord>> $groups */
        $groups = [];
        foreach ($this->records as $record) {
            $groups[$record->key()][] = $record;
        }

        $collapsed = [];
        foreach ($groups as $group) {
            usort($group, static fn (TownRecord $a, TownRecord $b): int => strcmp($a->zip, $b->zip));
            $first = $group[0];
            $zips = array_values(array_unique(array_map(static fn (TownRecord $r): string => $r->zip, $group)));

            $collapsed[] = new TownRecord(
                $first->zip,
                $first->name,
                $first->canton,
                $first->bfsNumber,
                $first->lat,
                $first->lng,
                $first->population,
                $zips,
            );
        }

        $order = array_flip(array_map(static fn (Canton $canton): string => $canton->value, Canton::cases()));
        usort(
            $collapsed,
            static fn (TownRecord $a, TownRecord $b): int => [$order[$a->canton->value], $a->name] <=> [$order[$b->canton->value], $b->name],
        );

        return new self($collapsed);
    }

    private static function parseRow(string $line, int $lineNumber): TownRecord
    {
        $fields = str_getcsv($line, ',', '"', '');
        if (7 !== \count($fields)) {
            throw InvalidTownDataset::invalidRow($lineNumber, \sprintf('expected 7 fields, got %d', \count($fields)));
        }

        [$zip, $name, $cantonCode, $bfsNumber, $lat, $lng, $population] = array_map(
            static fn (?string $field): string => trim((string) $field),
            $fields,
        );

        if (1 !== preg_match('/^\d{4}$/', $zip)) {
            throw InvalidTownDataset::invalidRow($lineNumber, \sprintf('invalid zip "%s"', $zip));
        }
        if ('' === $name || mb_strlen($name) > 100) {
            throw InvalidTownDataset::invalidRow($lineNumber, 'name must have 1 to 100 characters');
        }
        $canton = Canton::tryFrom(strtolower($cantonCode));
        if (null === $canton) {
            throw InvalidTownDataset::invalidRow($lineNumber, \sprintf('unknown canton "%s"', $cantonCode));
        }
        if (1 !== preg_match('/^[1-9]\d*$/', $bfsNumber)) {
            throw InvalidTownDataset::invalidRow($lineNumber, \sprintf('invalid bfs_number "%s"', $bfsNumber));
        }
        if (!is_numeric($lat) || (float) $lat < 45.8 || (float) $lat > 47.9) {
            throw InvalidTownDataset::invalidRow($lineNumber, \sprintf('lat "%s" outside Switzerland', $lat));
        }
        if (!is_numeric($lng) || (float) $lng < 5.9 || (float) $lng > 10.6) {
            throw InvalidTownDataset::invalidRow($lineNumber, \sprintf('lng "%s" outside Switzerland', $lng));
        }
        if (1 !== preg_match('/^\d+$/', $population)) {
            throw InvalidTownDataset::invalidRow($lineNumber, \sprintf('invalid population "%s"', $population));
        }

        return new TownRecord($zip, $name, $canton, (int) $bfsNumber, (float) $lat, (float) $lng, (int) $population, [$zip]);
    }
}
