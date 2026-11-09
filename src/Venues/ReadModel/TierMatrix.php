<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Venues\ValueObject\TierFeature;

/**
 * The Free/Pro comparison of `/location-eintragen` (G13), built from the `tiers` rows alone, so a staff edit in
 * «Tarife» changes the page. Columns run from the cheapest tier up. A row appears only while at least one tier offers
 * its feature (D18): the page never lists a flag that is off everywhere.
 */
final readonly class TierMatrix
{
    /**
     * @param list<TierColumn>    $columns
     * @param list<TierMatrixRow> $rows
     */
    public function __construct(
        public array $columns,
        public array $rows,
    ) {
    }

    /**
     * @param list<TierDefinition> $tiers
     */
    public static function fromDefinitions(array $tiers): self
    {
        usort($tiers, static fn (TierDefinition $a, TierDefinition $b): int => [$a->priceRappen, $a->rankWeight, $a->code] <=> [$b->priceRappen, $b->rankWeight, $b->code]);

        $columns = array_map(static fn (TierDefinition $tier): TierColumn => new TierColumn(
            $tier->code,
            $tier->name,
            $tier->priceRappen,
            $tier->vatLine,
            $tier->label()->promoted,
        ), $tiers);

        $rows = [];
        foreach (TierFeature::cases() as $feature) {
            $cells = array_map(static fn (TierDefinition $tier): bool|int|null => self::cell($feature, $tier), $tiers);
            if (array_any($cells, static fn (bool|int|null $cell): bool => self::offers($feature, $cell))) {
                $rows[] = new TierMatrixRow($feature, $cells);
            }
        }

        return new self($columns, $rows);
    }

    /**
     * The names of the tiers that carry the «Top-Eintrag» label, cheapest first.
     *
     * @return list<string>
     */
    public function promotedTierNames(): array
    {
        return array_values(array_map(
            static fn (TierColumn $column): string => $column->name,
            array_filter($this->columns, static fn (TierColumn $column): bool => $column->promoted),
        ));
    }

    private static function cell(TierFeature $feature, TierDefinition $tier): bool|int|null
    {
        return match ($feature) {
            TierFeature::Promoted => $tier->label()->promoted,
            TierFeature::Photos => $tier->photosMax,
            TierFeature::Videos => $tier->videosMax,
            TierFeature::Rooms => $tier->roomsMax,
            TierFeature::Documents => $tier->documentsMax,
            TierFeature::Contact => $tier->contactVisible,
            TierFeature::EventTypes => $tier->eventTypesMax,
            TierFeature::LastMinute => $tier->lastMinuteAllowed,
            TierFeature::DetailedReport => $tier->detailedReport,
        };
    }

    private static function offers(TierFeature $feature, bool|int|null $cell): bool
    {
        return true === $cell || (\is_int($cell) && $cell > 0) || (null === $cell && TierFeature::EventTypes === $feature);
    }
}
