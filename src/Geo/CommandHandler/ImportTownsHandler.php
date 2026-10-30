<?php

declare(strict_types=1);

namespace App\Geo\CommandHandler;

use App\Geo\Command\ImportTowns;
use App\Geo\Repository\TownRepository;
use App\Geo\ValueObject\ImportTownsResult;
use App\Geo\ValueObject\TownDataset;
use App\Geo\ValueObject\TownRecord;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Uid\EntityId;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Loads the towns CSV, collapses it to one town per (canton, name) and upserts towns and
 * their ZIPs. Towns are never deleted (D6); ZIP rows that left the dataset are.
 */
final class ImportTownsHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly TownRepository $towns,
    ) {
    }

    public function __invoke(ImportTowns $command): ImportTownsResult
    {
        $records = TownDataset::fromCsvFile($command->csvPath)->collapsed()->records();
        // German transliteration (ü → ue) regardless of the request locale.
        $slugger = new AsciiSlugger('de');

        $counts = $this->towns->upsertTowns(array_map(
            static fn (TownRecord $record): array => [
                'id' => EntityId::generate(),
                'canton_code' => $record->canton->value,
                'zip' => $record->zip,
                'name' => $record->name,
                'slug' => $slugger->slug($record->name)->lower()->toString(),
                'bfs_number' => $record->bfsNumber,
                'lat' => $record->lat,
                'lng' => $record->lng,
                'population' => $record->population,
            ],
            $records,
        ));

        $ids = $this->towns->townIdsByCantonAndName();
        $pairs = [];
        foreach ($records as $record) {
            foreach ($record->zips as $zip) {
                $pairs[] = ['zip' => $zip, 'town_id' => $ids[$record->key()]];
            }
        }

        $zipsAdded = $this->towns->upsertZips($pairs);
        $zipsRemoved = $this->towns->deleteZipsNotIn($pairs);
        $stale = $this->towns->staleTownNames(array_map(static fn (TownRecord $record): string => $record->key(), $records));

        return new ImportTownsResult(
            inserted: $counts['inserted'],
            updated: $counts['updated'],
            unchanged: \count($records) - $counts['inserted'] - $counts['updated'],
            zips: $this->towns->countZips(),
            zipsAdded: $zipsAdded,
            zipsRemoved: $zipsRemoved,
            staleTowns: $stale,
        );
    }
}
