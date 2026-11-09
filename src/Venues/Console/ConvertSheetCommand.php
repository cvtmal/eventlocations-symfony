<?php

declare(strict_types=1);

namespace App\Venues\Console;

use App\Venues\ContentYamlFile;
use App\Venues\EntitlementResolver;
use App\Venues\Sheet\VenueSheet;
use App\Venues\Sheet\VenueSheetRow;
use App\Venues\ValueObject\KnownTiers;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turns the venue sheet (F14, docs/content-format.md "Venue sheet") into draft content folders: one
 * `<path>/venues/<key>/venue.yaml` with `status: unpublished` per valid row, for `app:venues:import` to read next.
 *
 * Each row is `created`, `exists` (its folder is already there and is never overwritten: the folder is the truth
 * from then on) or `failed` (row rules, then the content schema; a key used twice in the sheet). A key the row
 * leaves empty is made from name and town and printed, so the owner can copy it back into the sheet. More event
 * types than Free allows are a note, never trimmed (F12). Writes no database row; exit 1 when any row failed, the
 * other rows are still written.
 */
#[AsCommand(name: 'app:venues:convert-sheet', description: 'Write a draft content folder for every row of the venue sheet (CSV)')]
final class ConvertSheetCommand
{
    public function __construct(
        private readonly VenueSheet $sheet,
        private readonly EntitlementResolver $entitlements,
        #[Autowire('%app.content_root%')]
        private readonly string $contentRoot,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The venue sheet (CSV, comma or semicolon)')]
        string $csv,
        #[Argument('The content root that holds venues/ (default: CONTENT_ROOT)')]
        ?string $path = null,
        #[Option('Check every row and say what would be written; write nothing')]
        bool $dryRun = false,
    ): int {
        try {
            $rows = VenueSheet::read($csv);
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
        $root = rtrim($path ?? $this->contentRoot, '/');
        $free = $this->entitlements->forTier(KnownTiers::FREE);

        $counts = ['created' => 0, 'exists' => 0, 'failed' => 0];
        $errors = [];
        $notes = [];
        $seen = [];
        foreach ($rows as $row) {
            $key = $row->key();
            $rowErrors = $this->sheet->check($row);
            if (isset($seen[$key])) {
                $rowErrors[] = \sprintf('row %d: key: %s is already used in row %d', $row->line, $key, $seen[$key]);
            }
            $seen[$key] ??= $row->line;
            if ([] !== $rowErrors) {
                ++$counts['failed'];
                $errors = [...$errors, ...$rowErrors];
                $io->writeln(\sprintf('%-8s  row %d', 'failed', $row->line));

                continue;
            }

            $file = $root.'/venues/'.$key.'/venue.yaml';
            $status = is_dir(\dirname($file)) ? 'exists' : 'created';
            ++$counts[$status];
            if ('created' === $status && !$dryRun) {
                ContentYamlFile::replace($file, ContentYamlFile::dump($row->toDocument()));
            }
            $io->writeln(\sprintf('%-8s  %s%s', $status, $key, $row->keyIsDerived() ? \sprintf(' (key of row %d, from name and town)', $row->line) : ''));
            $notes = [...$notes, ...self::notes($row, $key, $free->eventTypesMax, $free->tierName)];
        }

        $io->writeln(\sprintf(
            '%sRows: %d read, %d created, %d exist, %d failed.',
            $dryRun ? 'Dry run: ' : '',
            \count($rows),
            $counts['created'],
            $counts['exists'],
            $counts['failed'],
        ));
        if ([] !== $notes) {
            $io->writeln('Notes:');
            $io->listing($notes);
        }
        if ([] !== $errors) {
            $io->error(\sprintf('%d row%s failed:', $counts['failed'], 1 === $counts['failed'] ? '' : 's'));
            $io->listing($errors);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private static function notes(VenueSheetRow $row, string $key, ?int $eventTypesMax, string $tierName): array
    {
        $eventTypes = \count($row->eventTypes());

        return null !== $eventTypesMax && $eventTypes > $eventTypesMax
            ? [\sprintf('%s: event_types: %d > %d (%s)', $key, $eventTypes, $eventTypesMax, $tierName)]
            : [];
    }
}
